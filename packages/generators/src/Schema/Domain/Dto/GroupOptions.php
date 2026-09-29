<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\PhpType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeScriptType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Descriptor\Domain\ShapeParts;
use Cbox\Cms\Generators\Descriptor\Domain\ValidationRuleName;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\GroupFieldType;
use Cbox\Cms\Generators\Schema\Domain\OptionRules;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;
use Override;

/**
 * A `group` field: nested fields, once or repeated (PRD 11.6). The nested fields have no
 * classification of their own.
 */
#[Internal]
final readonly class GroupOptions implements FieldOptions
{
    /**
     * @param  list<FieldBlueprint>  $fields  in the order of the file
     * @param  ?GroupRepeat  $repeat  null when the group occurs once
     */
    public function __construct(
        public array $fields,
        public ?GroupRepeat $repeat,
    ) {}

    #[Override]
    public function typeName(): string
    {
        return GroupFieldType::NAME;
    }

    #[Override]
    public function problems(SourceLocation $field): array
    {
        return $this->repeat instanceof GroupRepeat
            ? OptionRules::items($this->repeat->minItems, $this->repeat->maxItems, $field->below('repeat'), GroupRepeat::DEFAULT_MAX_ITEMS)
            : [];
    }

    #[Override]
    public function nestedFields(): array
    {
        return $this->fields;
    }

    /**
     * A JSONB object, or a JSONB array of at most `max_items` objects for a repeated group. The
     * repeated group's check is a CASE, so a value that is not an array fails it instead of making
     * jsonb_array_length() raise an error, and its ELSE is true for null, so a group that is not
     * required may be left out.
     */
    #[Override]
    public function describeColumn(string $column): ColumnShape
    {
        if (! $this->repeat instanceof GroupRepeat) {
            return new ColumnShape('jsonb', [sprintf("jsonb_typeof(%s) = 'object'", $column)]);
        }

        return new ColumnShape('jsonb', [sprintf(
            "CASE WHEN jsonb_typeof(%s) = 'array' THEN jsonb_array_length(%s) BETWEEN %d AND %d ELSE %s IS NULL END",
            $column,
            $column,
            $this->repeat->minItems ?? 0,
            $this->repeat->maxItems,
            $column,
        )]);
    }

    /**
     * An array shape of the nested fields in PHP and an object type in TypeScript, each key the
     * handle of a nested field and optional when its value may be null; a list of them for a
     * repeated group.
     */
    #[Override]
    public function describeValue(array $fields): ValueShape
    {
        $php = [];
        $typeScript = [];

        foreach ($fields as $field) {
            $key = $field->handle->value.($field->php->nullable ? '?' : '');
            $php[] = $key.': '.$field->php->docType();
            $typeScript[] = $key.': '.$field->typeScript->declaration();
        }

        $shape = 'array{'.implode(', ', $php).'}';
        $object = '{ '.implode('; ', $typeScript).' }';

        if (! $this->repeat instanceof GroupRepeat) {
            return new ValueShape(new PhpType('array', $shape), new TypeScriptType($object), [new ValidationRule(ValidationRuleName::Object)]);
        }

        return new ValueShape(
            new PhpType('array', 'list<'.$shape.'>'),
            new TypeScriptType('Array<'.$object.'>'),
            [new ValidationRule(ValidationRuleName::List), ...ShapeParts::itemRules($this->repeat->minItems, $this->repeat->maxItems)],
        );
    }
}
