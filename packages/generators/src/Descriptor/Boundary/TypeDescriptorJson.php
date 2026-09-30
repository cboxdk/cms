<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Generation\Domain\Dto\ExtensionVersion;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;

/**
 * The canonical JSON of a type descriptor (PRD 11.2, 11.12): the golden form a test compares with a
 * committed file, and the compiled descriptor schema_versions stores. Every key is present, null
 * when it has no value; the keys of every object are written in sorted order; lists keep the
 * descriptor's order,
 * which is sorted already; and the text is pretty-printed with four spaces, unescaped slashes and
 * unescaped Unicode, and ends with one newline. The same descriptor always gives the same bytes.
 *
 * The descriptor's source locations are left out, because they say where a file lies, not what the
 * type is. `descriptor` is the version of this form.
 */
#[Internal]
final readonly class TypeDescriptorJson
{
    /** The version of the canonical form. */
    public const int FORMAT = 1;

    public static function encode(TypeDescriptor $type): string
    {
        $document = [
            'capabilities' => [
                'history' => $type->capabilities->history->value,
                'localization' => $type->capabilities->localization->value,
                'routable' => $type->capabilities->routable,
                'stages' => $type->capabilities->stages->value,
            ],
            'description' => $type->description,
            'descriptor' => self::FORMAT,
            'extensions' => array_map(
                static fn (ExtensionVersion $extension): array => ['namespace' => $extension->namespace->value, 'version' => $extension->version],
                $type->extensions,
            ),
            'fields' => array_map(self::field(...), $type->fields),
            'handle' => $type->handle->value,
            'label' => $type->label,
            'name' => $type->name(),
            'owner' => $type->owner->value,
            'type_id' => $type->typeId->toString(),
            'version' => $type->version,
        ];

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(FieldDescriptor $field): array
    {
        return [
            'agents' => $field->agents,
            'base' => $field->base,
            'choices' => array_map(
                static fn (SelectOption $option): array => ['label' => $option->label, 'value' => $option->value->value],
                $field->choices,
            ),
            'classification' => $field->classification->value,
            'column' => $field->column instanceof ColumnDescriptor ? [
                'checks' => $field->column->checks,
                'name' => $field->column->name,
                'not_null' => $field->column->notNull,
                'type' => $field->column->type,
            ] : null,
            'description' => $field->description,
            'encrypted' => $field->encrypted,
            'fields' => array_map(self::field(...), $field->fields),
            'filterable' => $field->filterable,
            'handle' => $field->handle->value,
            'label' => $field->label,
            'namespace' => $field->namespace?->value,
            'owner' => $field->owner->value,
            'php' => ['doc' => $field->php->doc, 'native' => $field->php->native, 'nullable' => $field->php->nullable],
            'required' => $field->required,
            'sortable' => $field->sortable,
            'type' => $field->type,
            'typescript' => ['nullable' => $field->typeScript->nullable, 'type' => $field->typeScript->type],
            'validation' => array_map(
                static fn (ValidationRule $rule): array => ['arguments' => $rule->arguments, 'rule' => $rule->name->value],
                $field->validation,
            ),
        ];
    }
}
