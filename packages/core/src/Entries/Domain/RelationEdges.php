<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Domain\Dto\RelationEdge;
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
 */
#[Internal]
final readonly class RelationEdges
{
    public const string RELATION = 'relation';

    /**
     * @return list<RelationEdge>
     */
    public static function of(TypeDefinition $type, VariantKey $variant, FieldValues $values): array
    {
        /** @var array<string, list<EntryId>> $targets by field address, in order */
        $targets = [];

        foreach ($type->fieldsOf($variant) as $field) {
            $map = $field->namespace instanceof FieldNamespace ? $values->extension($field->namespace) : $values->own;
            $value = $map instanceof FieldMap ? $map->get($field->handle) : null;

            if ($value instanceof FieldValue) {
                self::collect($field, $value, $field->address(), $targets);
            }
        }

        $edges = [];

        foreach ($targets as $address => $entries) {
            foreach ($entries as $ordinal => $target) {
                $edges[] = new RelationEdge($address, $ordinal, $target);
            }
        }

        return $edges;
    }

    /**
     * The edges of the variant's fields in the stage.
     */
    public static function variant(TypeDefinition $type, EntryId $entry, VariantKey $variant, RelationStage $stage, FieldValues $values): VariantEdges
    {
        return new VariantEdges($entry, $variant, $stage, self::of($type, $variant, $values));
    }

    /**
     * @param  array<string, list<EntryId>>  $targets
     */
    private static function collect(FieldDefinition $field, FieldValue $value, string $address, array &$targets): void
    {
        if ($field->valueType() === self::RELATION) {
            $items = $value instanceof ListValue ? $value->items : [$value];

            foreach ($items as $item) {
                $entry = $item instanceof TextValue ? self::entry($item) : null;

                if ($entry instanceof EntryId) {
                    $targets[$address][] = $entry;
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

    private static function entry(TextValue $value): ?EntryId
    {
        try {
            return EntryId::fromString($value->value);
        } catch (InvalidUuid7) {
            return null;
        }
    }
}
