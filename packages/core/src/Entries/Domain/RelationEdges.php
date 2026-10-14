<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\InvalidLink;
use Cbox\Cms\Contracts\Fields\Link;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\RelationDefinition;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\TypeTables\AttributeFilter;
use Cbox\Cms\Core\Entries\Domain\Dto\RelationEdge;
use Cbox\Cms\Core\Entries\Domain\Dto\RelationTarget;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantEdges;

/**
 * The relation edges of a variant's fields (PRD 5.11), read with the type as the TypeCatalog gives
 * it, never a type the kernel knows by name (GUARDRAILS 2.4): each field whose value type is
 * `relation`, a target or a list of them, among the top-level fields the variant holds
 * (TypeDefinition::fieldsOf()), the owner's and each extender's, and the fields of groups,
 * repeated or not, at any depth. Fields of the type the variant does not hold are left out, so a
 * language's row, which carries the shared variant's fields too, gives the language's edges alone.
 *
 * An edge names the field by its address without the items' indexes (`<group>.<handle>`), and the
 * ordinal counts the field's targets in the variant in the order the fields hold them, across the
 * items of a repeated group. A value that is not an entry's id gives no edge, because the input
 * validator refuses it before the commit.
 *
 * A relation whose targets carry attributes (RelationDefinition::$meta) holds a group per target,
 * the entry under `entry` and the attributes beside it: its edge carries those attributes, and the
 * text of its filterable attribute as its filter key (AttributeFilter::textOf()). A
 * released edge of a top-level relation with a sort_by field carries that field's value of the
 * same variant's fields as its sort key, which the kernel writes in the transaction of the release;
 * a draft edge carries none.
 *
 * A link field's value is an edge too when it names an entry or a node: its target is that entry
 * or node, and a link to an entry in a locale carries the locale as its attribute. A link to a URL
 * gives no edge.
 */
#[Internal]
final readonly class RelationEdges
{
    /** The value type of a link field, whose entry or node target is an edge too (B2-T76). */
    private const string LINK = 'link';

    /**
     * @return list<RelationEdge>
     */
    public static function of(TypeDefinition $type, VariantKey $variant, FieldValues $values, bool $sorted = false): array
    {
        /** @var array<string, list<RelationTarget>> $targets by field address, in order */
        $targets = [];
        /** @var array<string, FieldValue> $sortKeys by field address */
        $sortKeys = [];

        foreach ($type->fieldsOf($variant) as $field) {
            $value = self::valueOf($field, $values);

            if ($value instanceof FieldValue) {
                self::collect($field, $value, $field->address(), $targets);
            }

            $sortKey = $type->sortKeyOf($field);

            if ($sorted && $sortKey instanceof FieldDefinition) {
                $sortKeys[$field->address()] = self::valueOf($sortKey, $values) ?? new NullValue;
            }
        }

        $edges = [];

        foreach ($targets as $address => $entries) {
            foreach ($entries as $ordinal => $target) {
                $edges[] = new RelationEdge($address, $ordinal, $target->target, $target->meta, $target->filterKey, $sortKeys[$address] ?? null);
            }
        }

        return $edges;
    }

    /**
     * The edges of the variant's fields in the stage, with their sort keys in the released stage.
     */
    public static function variant(TypeDefinition $type, EntryId $entry, VariantKey $variant, RelationStage $stage, FieldValues $values): VariantEdges
    {
        return new VariantEdges($entry, $variant, $stage, self::of($type, $variant, $values, $stage === RelationStage::Released));
    }

    private static function valueOf(FieldDefinition $field, FieldValues $values): ?FieldValue
    {
        $map = $field->namespace instanceof FieldNamespace ? $values->extension($field->namespace) : $values->own;

        return $map instanceof FieldMap ? $map->get($field->handle) : null;
    }

    /**
     * @param  array<string, list<RelationTarget>>  $targets
     */
    private static function collect(FieldDefinition $field, FieldValue $value, string $address, array &$targets): void
    {
        if ($field->valueType() === self::LINK) {
            $target = self::link($value);

            if ($target instanceof RelationTarget) {
                $targets[$address][] = $target;
            }

            return;
        }

        if ($field->isRelation()) {
            $items = $value instanceof ListValue ? $value->items : [$value];

            foreach ($items as $item) {
                $target = self::target($field, $item);

                if ($target instanceof RelationTarget) {
                    $targets[$address][] = $target;
                }
            }

            return;
        }

        if ($field->fields === []) {
            return;
        }

        $groups = $value instanceof GroupValue ? [$value] : ($value instanceof ListValue ? $value->items : []);

        foreach ($groups as $group) {
            if (! $group instanceof GroupValue) {
                continue;
            }

            foreach ($field->fields as $nested) {
                $nestedValue = $group->fields->get($nested->handle);

                if ($nestedValue instanceof FieldValue) {
                    self::collect($nested, $nestedValue, $address.'.'.$nested->handle->value, $targets);
                }
            }
        }
    }

    /**
     * The entry of one target and the attributes it carries: a group of `entry` and the attributes
     * for a relation that declares them, the entry's id for another; null for a value that names
     * no entry.
     */
    private static function target(FieldDefinition $field, FieldValue $item): ?RelationTarget
    {
        if ($field->relation?->meta !== true) {
            $entry = $item instanceof TextValue ? self::entry($item) : null;

            return $entry instanceof EntryId ? new RelationTarget($entry, null) : null;
        }

        if (! $item instanceof GroupValue) {
            return null;
        }

        $entry = $item->fields->get(new FieldHandle(RelationDefinition::ENTRY));
        $attributes = array_values(array_filter(
            $item->fields->fields,
            static fn (NamedValue $named): bool => $named->handle->value !== RelationDefinition::ENTRY,
        ));

        $id = $entry instanceof TextValue ? self::entry($entry) : null;
        $filterable = array_find($field->relationMeta(), static fn (FieldDefinition $attribute): bool => $attribute->filterable);
        $filtered = $filterable instanceof FieldDefinition ? $item->fields->get($filterable->handle) : null;

        return $id instanceof EntryId
            ? new RelationTarget($id, new FieldMap(...$attributes), $filtered instanceof FieldValue ? AttributeFilter::textOf($filtered) : null)
            : null;
    }

    /**
     * The entry or the node a link names, with the locale of a link to an entry as its attribute;
     * null for a link to a URL or a value that is no link.
     */
    private static function link(FieldValue $value): ?RelationTarget
    {
        if ($value instanceof NullValue) {
            return null;
        }

        try {
            $link = Link::fromFieldValue($value);
        } catch (InvalidLink) {
            return null;
        }

        $target = $link->target();

        if ($target === null) {
            return null;
        }

        return new RelationTarget($target, $link->locale instanceof Locale
            ? new FieldMap(new NamedValue(new FieldHandle(Link::LOCALE), new TextValue($link->locale->value)))
            : null);
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
