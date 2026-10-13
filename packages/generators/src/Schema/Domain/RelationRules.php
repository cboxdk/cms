<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\Dto\Blueprints;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\RelationOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\TypeBlueprint;

/**
 * The rules of a relation field (PRD 5.11) that JSON Schema cannot express:
 *
 * - every type its `types` names, `<owner>:<handle>`, is a type a blueprint file defines, for the
 *   relation fields of every type and extension and the fields nested in them
 *   (generate_unknown_relation_type). A type may name itself, and a type of another owner. A
 *   relation with `meta` names its types through the nested field of its entry, which is declared
 *   where the relation is, so each unknown type is named once;
 * - a filterable relation's `sort_by` names a sortable top-level field of the type's owner that
 *   the same variant holds, translatable as the relation is, so the field's value is in the
 *   revision the kernel writes the relation's released edges from (generate_relation_sort_by_invalid).
 *   For an extension's relation, the type is the one it extends.
 *
 * A file that could not be read may define the type, so the first rule holds only when every file
 * was read, as an unknown `extends` does.
 */
#[Internal]
final readonly class RelationRules
{
    /**
     * @param  bool  $complete  whether every blueprint file below the schema roots was read
     * @return list<GenerationProblem>
     */
    public static function problems(Blueprints $blueprints, bool $complete): array
    {
        $known = [];
        $byId = [];

        foreach ($blueprints->types as $type) {
            $known[$type->owner->value.':'.$type->handle->value] = true;
            $byId[$type->typeId->toString()] = $type;
        }

        $problems = [];

        foreach ($blueprints->types as $type) {
            self::fields($type->fields, $complete ? $known : null, $problems);
            self::sortBy($type->fields, $type, $problems);
        }

        foreach ($blueprints->extensions as $extension) {
            self::fields($extension->fields, $complete ? $known : null, $problems);
            $type = $byId[$extension->extends->toString()] ?? null;

            if ($type instanceof TypeBlueprint) {
                self::sortBy($extension->fields, $type, $problems);
            }
        }

        return $problems;
    }

    /**
     * @param  list<FieldBlueprint>  $fields
     * @param  ?array<string, true>  $known  the names of the types the blueprints define; null when not every file was read
     * @param  list<GenerationProblem>  $problems
     */
    private static function fields(array $fields, ?array $known, array &$problems): void
    {
        if ($known === null) {
            return;
        }

        foreach ($fields as $field) {
            self::fields($field->options->nestedFields(), $known, $problems);

            if (! $field->options instanceof RelationOptions || $field->options->carriesMeta()) {
                continue;
            }

            foreach ($field->options->typeNames() as $index => $name) {
                if (! isset($known[$name])) {
                    $problems[] = new GenerationProblem(GenerateErrorCode::UnknownRelationType, sprintf(
                        '%s: no blueprint file below the schema roots defines the type %s, so the relation %s cannot hold its entries. Name a type <owner>:<handle> that a blueprint defines, such as %s, or add the schema root of its owner.',
                        $field->location->below('types', $index)->describe(),
                        $name,
                        $field->handle->value,
                        $known === [] ? 'app:article' : array_key_first($known),
                    ));
                }
            }
        }
    }

    /**
     * The top-level relations' `sort_by`, against the fields of the type's owner.
     *
     * @param  list<FieldBlueprint>  $fields  the top-level fields of the type or of an extension of it
     * @param  list<GenerationProblem>  $problems
     */
    private static function sortBy(array $fields, TypeBlueprint $type, array &$problems): void
    {
        foreach ($fields as $field) {
            if (! $field->options instanceof RelationOptions || ! $field->options->sortBy instanceof Handle) {
                continue;
            }

            $handle = $field->options->sortBy;
            $target = array_find($type->fields, static fn (FieldBlueprint $own): bool => $own->handle->equals($handle));
            $reason = match (true) {
                ! $target instanceof FieldBlueprint => sprintf('the type %s:%s has no field %s', $type->owner->value, $type->handle->value, $handle->value),
                ! $target->sortable => sprintf('the field %s is not sortable', $handle->value),
                $target->translatable !== $field->translatable => sprintf(
                    'the field %s is %s and the relation is %s, so another variant holds it',
                    $handle->value,
                    $target->translatable ? 'translatable' : 'shared',
                    $field->translatable ? 'translatable' : 'shared',
                ),
                default => null,
            };

            if ($reason !== null) {
                $problems[] = new GenerationProblem(GenerateErrorCode::RelationSortByInvalid, sprintf(
                    '%s: the relation %s sorts its edges by %s, but %s. Name a sortable top-level field of the owner that the same variant holds.',
                    $field->location->below('sort_by')->describe(),
                    $field->handle->value,
                    $handle->value,
                    $reason,
                ));
            }
        }
    }
}
