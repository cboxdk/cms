<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\Dto\BooleanOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\ConditionEdge;
use Cbox\Cms\Generators\Schema\Domain\Dto\ConditionReference;
use Cbox\Cms\Generators\Schema\Domain\Dto\ConditionTarget;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldCondition;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\ShapedOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TextOptions;

/**
 * The rules of the conditions of a set of fields (gap G3) that JSON Schema cannot express, because
 * they compare a condition with the fields it names. A set is the owner's fields of a type, or the
 * fields one extender adds to one type across its extension files. Each rule has its own code, and
 * names the place of the comparison that breaks it:
 *
 * - a condition names a field it can reach: a sibling, or with `../` a sibling of a group the field
 *   is in, or as `ext.<namespace>.<handle>` a top-level field of the set (generate_when_unknown_field);
 * - `ext.<namespace>.<handle>` names the field's own namespace, never the owner's fields or another
 *   extender's (generate_when_foreign_namespace);
 * - the field named is classified at most as the conditional field, whose readers must be able to
 *   tell whether it is shown (generate_when_classification_above); a field inside a group has its
 *   group's classification;
 * - in a type with localization variants, the field named is held by the same variant
 *   (generate_when_other_variant, PRD 10.1);
 * - `eq`, `neq` and `in` compare a text, long text or select field with strings, a select's
 *   options only, an integer with whole numbers and a boolean with booleans; a field of another
 *   base, and a select with multiple, takes only `null` and `not_null`
 *   (generate_when_value_wrong_kind);
 * - the conditions do not read each other in a circle, and no field reads itself or a group it is
 *   in (generate_when_cycle).
 */
#[Internal]
final readonly class ConditionRules
{
    /**
     * @param  list<FieldBlueprint>  $fields  the top-level fields of the set
     * @param  ?Owner  $namespace  the extender, or null for the owner's fields
     * @param  bool  $variants  whether the type has localization variants
     * @return list<GenerationProblem>
     */
    public static function problems(array $fields, ?Owner $namespace, bool $variants): array
    {
        $problems = [];
        /** @var array<string, list<ConditionEdge>> $edges by the path of the conditional field */
        $edges = [];
        self::walk($fields, [], [], null, $namespace, $variants, $problems, $edges);
        array_push($problems, ...self::cycles($edges));

        return $problems;
    }

    /**
     * @param  list<FieldBlueprint>  $fields  the fields of one object
     * @param  list<list<FieldBlueprint>>  $ancestors  the fields of each object the object is in, the outermost first
     * @param  list<FieldBlueprint>  $groups  the groups the object is in, the outermost first
     * @param  ?FieldBlueprint  $topLevel  the top-level field the object is in, or null at the top
     * @param  list<GenerationProblem>  $problems
     * @param  array<string, list<ConditionEdge>>  $edges
     */
    private static function walk(array $fields, array $ancestors, array $groups, ?FieldBlueprint $topLevel, ?Owner $namespace, bool $variants, array &$problems, array &$edges): void
    {
        $scopes = [...$ancestors, $fields];

        foreach ($fields as $field) {
            $top = $topLevel ?? $field;
            $path = self::path([...$groups, $field]);

            foreach ($field->when?->comparisons() ?? [] as $comparison) {
                $reference = $comparison->field;

                if (! $reference instanceof ConditionReference) {
                    continue;
                }

                $target = self::target($reference, $scopes, $groups, $field, $comparison, $namespace, $problems);

                if (! $target instanceof ConditionTarget) {
                    continue;
                }

                $targetField = $target->field;
                $targetTop = $target->topLevel;
                $edges[$path][] = new ConditionEdge($target->path, $comparison);
                self::classification($top, $targetTop, $comparison, $problems);

                if ($variants && $top->translatable !== $targetTop->translatable) {
                    $problems[] = self::problem(GenerateErrorCode::WhenOtherVariant, $comparison, sprintf(
                        'the condition of %s reads %s, and %s is %s while %s is %s. A revision holds the fields of one variant (PRD 10.1): read a field the same variant holds.',
                        $field->handle->value,
                        $reference->written(),
                        $top->handle->value,
                        $top->translatable ? 'translatable' : 'shared',
                        $targetTop->handle->value,
                        $targetTop->translatable ? 'translatable' : 'shared',
                    ));
                }

                $kind = self::kind($targetField, $comparison);

                if ($kind !== null) {
                    $problems[] = self::problem(GenerateErrorCode::WhenValueWrongKind, $comparison, sprintf(
                        'the condition compares %s with %s, and %s',
                        $reference->written(),
                        implode(', ', array_map(self::shown(...), $comparison->values)),
                        $kind,
                    ));
                }
            }

            $nested = $field->options->nestedFields();

            if ($nested !== []) {
                self::walk($nested, $scopes, [...$groups, $field], $top, $namespace, $variants, $problems, $edges);
            }
        }
    }

    /**
     * The field a reference names, with its path and its top-level field, or null after reporting
     * why it names none.
     *
     * @param  list<list<FieldBlueprint>>  $scopes
     * @param  list<FieldBlueprint>  $groups
     * @param  list<GenerationProblem>  $problems
     */
    private static function target(ConditionReference $reference, array $scopes, array $groups, FieldBlueprint $field, FieldCondition $comparison, ?Owner $namespace, array &$problems): ?ConditionTarget
    {
        if ($reference->namespace instanceof Owner && (! $namespace instanceof Owner || ! $namespace->equals($reference->namespace))) {
            $problems[] = self::problem(GenerateErrorCode::WhenForeignNamespace, $comparison, sprintf(
                'the condition of %s reads %s, a field of the namespace %s, and %s. A condition reads only the fields of its own set.',
                $field->handle->value,
                $reference->written(),
                $reference->namespace->value,
                $namespace instanceof Owner ? sprintf('the field is one %s adds', $namespace->value) : 'the field is one of the owner\'s',
            ));

            return null;
        }

        $level = $reference->namespace instanceof Owner ? 0 : count($scopes) - 1 - $reference->up;
        $siblings = $scopes[$level] ?? null;
        $target = $level >= 0 && $siblings !== null
            ? array_find($siblings, static fn (FieldBlueprint $sibling): bool => $sibling->handle->equals($reference->handle))
            : null;

        if (! $target instanceof FieldBlueprint) {
            $problems[] = self::problem(GenerateErrorCode::WhenUnknownField, $comparison, sprintf(
                'the condition of %s reads %s, and %s. Name a sibling, or with ../ a sibling of a group the field is in.',
                $field->handle->value,
                $reference->written(),
                $level < 0
                    ? sprintf('the field is in %d groups, fewer than the reference goes up', count($scopes) - 1)
                    : sprintf('no field there has the handle %s', $reference->handle->value),
            ));

            return null;
        }

        $targetGroups = array_slice($groups, 0, $level);
        $chain = [...$groups, $field];

        if ($target === ($chain[$level] ?? null)) {
            $problems[] = self::problem(GenerateErrorCode::WhenCycle, $comparison, sprintf(
                'the condition of %s reads %s, %s. Whether a field is shown cannot depend on itself or on a group that holds it.',
                $field->handle->value,
                $reference->written(),
                $target === $field ? 'the field itself' : 'a group the field is in',
            ));

            return null;
        }

        return new ConditionTarget($target, self::path([...$targetGroups, $target]), $targetGroups[0] ?? $target);
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private static function classification(FieldBlueprint $top, FieldBlueprint $targetTop, FieldCondition $comparison, array &$problems): void
    {
        $own = $top->classification;
        $read = $targetTop->classification;

        if (! $own instanceof Classification || ! $read instanceof Classification || ClassificationAccess::from($read->value)->rank() <= ClassificationAccess::from($own->value)->rank()) {
            return;
        }

        $problems[] = self::problem(GenerateErrorCode::WhenClassificationAbove, $comparison, sprintf(
            'the condition reads %s, classified %s, above the classification %s of the field it shows, so a reader who may see the field could not tell whether it is shown. Read a field classified %s or lower.',
            $comparison->field?->written() ?? '',
            $read->value,
            $own->value,
            $own->value,
        ));
    }

    /**
     * Why the comparison does not fit the field it reads, or null when it fits.
     */
    private static function kind(FieldBlueprint $target, FieldCondition $comparison): ?string
    {
        if (! $comparison->operator->takesValues()) {
            return null;
        }

        $options = $target->options instanceof ShapedOptions ? $target->options->base : $target->options;
        $values = $comparison->values;

        return match (true) {
            $options instanceof TextOptions, $options instanceof LongTextOptions => array_all($values, static fn (string|int|bool $value): bool => is_string($value)) ? null : 'a text field is compared with strings',
            $options instanceof IntegerOptions => array_all($values, static fn (string|int|bool $value): bool => is_int($value)) ? null : 'an integer field is compared with whole numbers',
            $options instanceof BooleanOptions => array_all($values, static fn (string|int|bool $value): bool => is_bool($value)) ? null : 'a boolean field is compared with true or false',
            $options instanceof SelectOptions && ! $options->multiple => self::options($options, $values),
            default => sprintf('a %s field cannot be compared: ask it for a value with null or not_null', $target->options->typeName().($options instanceof SelectOptions ? ' with multiple' : '')),
        };
    }

    /**
     * @param  list<string|int|bool>  $values
     */
    private static function options(SelectOptions $options, array $values): ?string
    {
        $known = array_map(static fn (SelectOption $option): string => $option->value->value, $options->options);

        return array_all($values, static fn (string|int|bool $value): bool => is_string($value) && in_array($value, $known, true))
            ? null
            : 'a select field is compared with its options: '.implode(', ', $known);
    }

    /**
     * The fields that read each other in a circle, each circle once, at the first of its fields.
     *
     * @param  array<string, list<ConditionEdge>>  $edges
     * @return list<GenerationProblem>
     */
    private static function cycles(array $edges): array
    {
        $problems = [];
        /** @var array<string, bool> $done */
        $done = [];

        foreach (array_keys($edges) as $path) {
            self::visit($path, [], $edges, $done, $problems);
        }

        return $problems;
    }

    /**
     * Walks the references from a field depth first, reporting each reference back to a field on
     * the trail as a circle.
     *
     * @param  list<string>  $trail  the fields walked to reach this one
     * @param  array<string, list<ConditionEdge>>  $edges
     * @param  array<string, bool>  $done  the fields every reference of which was walked
     * @param  list<GenerationProblem>  $problems
     */
    private static function visit(string $path, array $trail, array $edges, array &$done, array &$problems): void
    {
        if (isset($done[$path])) {
            return;
        }

        $trail[] = $path;

        foreach ($edges[$path] ?? [] as $edge) {
            $next = $edge->target;
            $comparison = $edge->comparison;
            $start = array_search($next, $trail, true);

            if (is_int($start)) {
                $problems[] = self::problem(GenerateErrorCode::WhenCycle, $comparison, sprintf(
                    'the conditions read each other in a circle: %s. Remove one of the references.',
                    implode(' -> ', [...array_slice($trail, $start), $next]),
                ));

                continue;
            }

            self::visit($next, $trail, $edges, $done, $problems);
        }

        $done[$path] = true;
    }

    /**
     * @param  list<FieldBlueprint>  $chain  the groups and the field, the outermost first
     */
    private static function path(array $chain): string
    {
        return implode('.', array_map(static fn (FieldBlueprint $field): string => $field->handle->value, $chain));
    }

    private static function shown(string|int|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            default => '"'.$value.'"',
        };
    }

    private static function problem(GenerateErrorCode $code, FieldCondition $comparison, string $what): GenerationProblem
    {
        return new GenerationProblem($code, sprintf('%s: %s', $comparison->location->describe(), $what));
    }
}
