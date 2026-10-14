<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\InvalidLink;
use Cbox\Cms\Contracts\Fields\Link;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Core\Pipeline\Domain\Dto\LinkReference;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RelationReference;

/**
 * The targets of the relation fields in a revision's field values (PRD 5.11), read with the
 * type's rules: each `relation` and each item of a `relations`, in the owner's fields, each
 * extender's under `ext.<namespace>` and the fields of groups, repeated or not, at any depth. A
 * value that is not an entry's id is left out, because the input validator already refuses it.
 *
 * A `link` that names an entry or a node gives a LinkReference at the path of its target, such as
 * `fields.source.entry` (B2-T76); a link to a URL, or a value that is no link, gives none.
 */
#[Internal]
final readonly class RelationReferences
{
    /**
     * @param  FieldPath  $at  the path of the fields in the command, such as `fields`
     * @return list<RelationReference|LinkReference>
     */
    public static function of(TypeRules $rules, FieldValues $values, FieldPath $at): array
    {
        $references = self::fields($rules->fields, $values->own, $at);

        foreach ($rules->extensions as $extension) {
            $map = $values->extension($extension->namespace);

            if ($map instanceof FieldMap) {
                array_push($references, ...self::fields($extension->fields, $map, $at->then(TypeRules::EXTENSIONS_KEY, $extension->namespace->value)));
            }
        }

        return $references;
    }

    /**
     * @param  list<FieldRules>  $rules
     * @return list<RelationReference|LinkReference>
     */
    private static function fields(array $rules, FieldMap $map, FieldPath $at): array
    {
        $references = [];

        foreach ($rules as $field) {
            $value = $map->get($field->handle);

            if ($value instanceof FieldValue) {
                array_push($references, ...self::value($field, $value, $at->then($field->handle->value)));
            }
        }

        return $references;
    }

    /**
     * @return list<RelationReference|LinkReference>
     */
    private static function value(FieldRules $field, FieldValue $value, FieldPath $path): array
    {
        if ($field->type === RuleName::Link) {
            return self::link($value, $path);
        }

        $targets = self::targets($field);

        if ($field->type === RuleName::Relation && $targets !== [] && $value instanceof TextValue) {
            $entry = self::entry($value);

            return $entry instanceof EntryId ? [new RelationReference($entry, $targets, $path)] : [];
        }

        if ($field->type === RuleName::Relations && $targets !== [] && $value instanceof ListValue) {
            $references = [];

            foreach ($value->items as $index => $item) {
                $entry = $item instanceof TextValue ? self::entry($item) : null;

                if ($entry instanceof EntryId) {
                    $references[] = new RelationReference($entry, $targets, $path, $index);
                }
            }

            return $references;
        }

        if ($field->fields === []) {
            return [];
        }

        if ($value instanceof GroupValue) {
            return self::fields($field->fields, $value->fields, $path);
        }

        if (! $value instanceof ListValue) {
            return [];
        }

        $references = [];

        foreach ($value->items as $index => $item) {
            if ($item instanceof GroupValue) {
                array_push($references, ...self::fields($field->fields, $item->fields, $path->then($index)));
            }
        }

        return $references;
    }

    /**
     * @return list<LinkReference>
     */
    private static function link(FieldValue $value, FieldPath $path): array
    {
        try {
            $link = Link::fromFieldValue($value);
        } catch (InvalidLink) {
            return [];
        }

        return match (true) {
            $link->entry instanceof EntryId => [new LinkReference($link->entry, $path->then(Link::ENTRY))],
            $link->node instanceof NodeId => [new LinkReference($link->node, $path->then(Link::NODE))],
            default => [],
        };
    }

    /**
     * @return list<TypeName>
     */
    private static function targets(FieldRules $field): array
    {
        $rule = $field->rule(RuleName::Targets);

        return $rule instanceof Rule ? array_map(static fn (string $name): TypeName => new TypeName($name), $rule->arguments) : [];
    }

    private static function entry(TextValue $value): ?EntryId
    {
        try {
            return EntryId::fromString($value->value);
        } catch (InvalidUuid7) {
            return null;
        }
    }
}
