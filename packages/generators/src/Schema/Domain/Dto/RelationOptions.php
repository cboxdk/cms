<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\PhpType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeScriptType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\RelationFieldType;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * A `relation` field (PRD 5.11, 11.6): a reference to one entry, or with `multiple` (default
 * false) to a list of at most `max_items` entries, each once, whose types are among `types`, in
 * the order of the file. Only a multiple relation has `max_items`, at most MAX_ITEMS, which is
 * also its default (PRD 11.6: at most 1,000 relations per field). RelationRules checks that every
 * type it names is defined.
 *
 * With `meta`, each target carries attributes (PRD 5.11), such as a role on a byline: the
 * relation then takes the form of a group, once or repeated up to `max_items` times, whose nested
 * fields are `entry`, the target as a relation to one entry of the same types, and the attributes,
 * so every generator writes, validates and stores a target with its attributes as it does a
 * group's item. Its targets need not differ, because one entry may hold two roles. `sort_by` names
 * the sortable field of the owner a filterable relation's released edges carry as their sort key;
 * RelationRules holds it to the type.
 */
#[Internal]
final readonly class RelationOptions implements FieldOptions
{
    public const bool DEFAULT_MULTIPLE = false;

    /** The most targets a multiple relation holds (PRD 11.6), and its default `max_items`. */
    public const int MAX_ITEMS = 1000;

    /** The table a to-one relation's column references (PRD 11.6). */
    public const string REFERENCED_TABLE = 'entries';

    /** The handle of the nested field that holds a target's entry beside its attributes. */
    public const string ENTRY = 'entry';

    public const string ENTRY_LABEL = 'Entry';

    public const string ENTRY_DESCRIPTION = 'The entry the relation points to.';

    /**
     * @param  non-empty-list<TypeName>  $types
     * @param  ?int  $maxItems  for a multiple relation; null for a to-one relation
     * @param  ?FieldBlueprint  $entry  the nested field of a target's entry, with $meta; null without
     * @param  list<FieldBlueprint>  $meta  the attributes each target carries, in the order of the file
     * @param  ?Handle  $sortBy  the owner's sortable field a filterable relation's edges are sorted by
     */
    public function __construct(
        public array $types,
        public bool $multiple,
        public ?int $maxItems,
        public ?FieldBlueprint $entry = null,
        public array $meta = [],
        public ?Handle $sortBy = null,
    ) {}

    /**
     * Whether each target carries attributes, so the relation takes the form of a group.
     */
    public function carriesMeta(): bool
    {
        return $this->entry instanceof FieldBlueprint;
    }

    #[Override]
    public function typeName(): string
    {
        return RelationFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        return [];
    }

    /**
     * The target's entry and the attributes, with `meta`; none without.
     */
    #[Override]
    public function nestedFields(): array
    {
        return $this->entry instanceof FieldBlueprint ? [$this->entry, ...$this->meta] : [];
    }

    /**
     * A to-one relation is a uuid with a foreign key to `entries`; a multiple relation is a jsonb
     * array of the entries' ids, at most `max_items`. The column of a multiple relation is checked
     * only for its form and length: an array, as for a repeated group, so ELSE holds for null. A
     * relation with `meta` has the column of its group form.
     */
    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
        $group = $this->group();

        if ($group instanceof GroupOptions) {
            return $group->describeColumn($column);
        }

        if (! $this->multiple) {
            return new ColumnShape('uuid', [], self::REFERENCED_TABLE);
        }

        return new ColumnShape('jsonb', [sprintf(
            "CASE WHEN jsonb_typeof(%s) = 'array' THEN jsonb_array_length(%s) <= %d ELSE %s IS NULL END",
            $column,
            $column,
            $this->maxItems ?? self::MAX_ITEMS,
            $column,
        )]);
    }

    /**
     * An EntryId, or a list of them; in TypeScript the id as a string. The rules are `relation` or
     * `relations`, then `targets` with the type names, and `max_items` for a multiple relation.
     * A relation with `meta` has the shape of its group form, an object or a list of them.
     */
    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        $group = $this->group();

        if ($group instanceof GroupOptions) {
            return $group->describeValue($fields);
        }

        $targets = new ValidationRule(ValidationRuleName::Targets, $this->typeNames());

        if (! $this->multiple) {
            return new ValueShape(
                new PhpType('\\'.EntryId::class, '\\'.EntryId::class),
                new TypeScriptType('string'),
                [new ValidationRule(ValidationRuleName::Relation), $targets],
            );
        }

        return new ValueShape(
            new PhpType('array', 'list<\\'.EntryId::class.'>'),
            new TypeScriptType('Array<string>'),
            [
                new ValidationRule(ValidationRuleName::Relations),
                $targets,
                new ValidationRule(ValidationRuleName::MaxItems, [(string) ($this->maxItems ?? self::MAX_ITEMS)]),
            ],
        );
    }

    /**
     * The names of the types a target may have, in the order of the file.
     *
     * @return list<string>
     */
    public function typeNames(): array
    {
        return array_map(static fn (TypeName $type): string => $type->value, $this->types);
    }

    /**
     * The group form of a relation with `meta`: its nested fields once, or repeated up to
     * `max_items` times for a multiple relation; null without `meta`.
     */
    public function group(): ?GroupOptions
    {
        if (! $this->entry instanceof FieldBlueprint) {
            return null;
        }

        return new GroupOptions($this->nestedFields(), $this->multiple ? new GroupRepeat(null, $this->maxItems ?? self::MAX_ITEMS) : null);
    }
}
