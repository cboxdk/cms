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
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * A `relation` field (PRD 5.11, 11.6): a reference to one entry, or with `multiple` (default
 * false) to a list of at most `max_items` entries, each once, whose types are among `types`, in
 * the order of the file. Only a multiple relation has `max_items`, at most MAX_ITEMS, which is
 * also its default (PRD 11.6: at most 1,000 relations per field). RelationRules checks that every
 * type it names is defined.
 */
#[Internal]
final readonly class RelationOptions implements FieldOptions
{
    public const bool DEFAULT_MULTIPLE = false;

    /** The most targets a multiple relation holds (PRD 11.6), and its default `max_items`. */
    public const int MAX_ITEMS = 1000;

    /** The table a to-one relation's column references (PRD 11.6). */
    public const string REFERENCED_TABLE = 'entries';

    /**
     * @param  non-empty-list<TypeName>  $types
     * @param  ?int  $maxItems  for a multiple relation; null for a to-one relation
     */
    public function __construct(
        public array $types,
        public bool $multiple,
        public ?int $maxItems,
    ) {}

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

    #[Override]
    public function nestedFields(): array
    {
        return [];
    }

    /**
     * A to-one relation is a uuid with a foreign key to `entries`; a multiple relation is a jsonb
     * array of the entries' ids, at most `max_items`. The column of a multiple relation is checked
     * only for its form and length: an array, as for a repeated group, so ELSE holds for null.
     */
    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
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
     */
    #[Override]
    public function describeValue(array $fields): ValueShape
    {
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
}
