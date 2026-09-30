<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedField;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\ResolvedType;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\ColumnName;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Dto\ShapedOptions;
use Cbox\Cms\Generators\Schema\Domain\Owner;

/**
 * Compiles the resolved schema into a type descriptor per type (PRD 11.2, 11.12), which every
 * generator reads instead of the blueprints.
 *
 * - A top-level field gets its column: the handle, or `ext__<namespace>__<handle>` for an
 *   extension field (ColumnName). A name over 63 bytes is refused with
 *   generate_column_name_too_long, never cut short, and a top-level field without a
 *   classification with generate_schema_invalid.
 * - A confidential field is encrypted (PRD 12.2): its column is `bytea` of ciphertext without
 *   checks. Otherwise the field type describes the column: its Postgres type and CHECK expressions.
 * - An owner's required field is NOT NULL, its PHP and TypeScript types are not nullable and its
 *   validator starts with `required`. An extension field never is, whatever its blueprint says,
 *   because the owner's code creates and revises entries without knowing it; its `required` is
 *   enforced when an entry is published (PRD 11.12, point 1). Every other field starts with
 *   `nullable`.
 * - A field inside a group has no column. It has its group's classification and encryption, is
 *   required within the group when its blueprint says so, and the nested fields are sorted by
 *   handle, so the descriptor does not depend on their order in the file.
 *
 * Every problem is collected before compile() fails.
 */
#[Internal]
final readonly class DescriptorCompiler
{
    /**
     * @throws GenerationFailed
     */
    public static function compile(ResolvedSchema $schema): CompiledSchema
    {
        $problems = [];
        $types = [];

        foreach ($schema->types as $type) {
            $types[] = self::type($type, $problems);
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        return new CompiledSchema($types);
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private static function type(ResolvedType $type, array &$problems): TypeDescriptor
    {
        $fields = [];

        foreach ($type->fields as $field) {
            $descriptor = self::topLevel($field, $problems);

            if ($descriptor instanceof FieldDescriptor && $descriptor->column instanceof ColumnDescriptor) {
                $fields[$descriptor->column->name] = $descriptor;
            }
        }

        ksort($fields, SORT_STRING);
        $blueprint = $type->blueprint;

        return new TypeDescriptor(
            $blueprint->typeId,
            $blueprint->owner,
            $blueprint->handle,
            $blueprint->label,
            $blueprint->description,
            $blueprint->version,
            $blueprint->capabilities,
            $type->extensions,
            array_values($fields),
            $blueprint->location,
        );
    }

    /**
     * @param  list<GenerationProblem>  $problems
     */
    private static function topLevel(ResolvedField $resolved, array &$problems): ?FieldDescriptor
    {
        $field = $resolved->blueprint;
        $namespace = $resolved->namespace;
        $column = $namespace instanceof Owner
            ? ColumnName::ofExtensionField($namespace, $field->handle)
            : ColumnName::ofTypeField($field->handle);

        if (! $column->fits()) {
            $problems[] = new GenerationProblem(GenerateErrorCode::ColumnNameTooLong, sprintf(
                '%s: the column name %s has %d bytes, and Postgres allows %d. Choose a shorter handle.',
                $field->location->describe(),
                $column->value,
                $column->bytes(),
                ColumnName::MAX_BYTES,
            ));

            return null;
        }

        if (! $field->classification instanceof Classification) {
            $problems[] = new GenerationProblem(GenerateErrorCode::SchemaInvalid, sprintf(
                '%s: a top-level field needs a classification (PRD 12.2).',
                $field->location->describe(),
            ));

            return null;
        }

        return self::field($field, $namespace, $field->classification, $column->value, ! $namespace instanceof Owner);
    }

    /**
     * @param  ?string  $column  the column of a top-level field, or null inside a group
     * @param  bool  $enforced  whether the owner's code enforces `required` when an entry is written
     */
    private static function field(FieldBlueprint $field, ?Owner $namespace, Classification $classification, ?string $column, bool $enforced): FieldDescriptor
    {
        $nested = [];

        foreach ($field->options->nestedFields() as $inner) {
            $nested[$inner->handle->value] = self::field($inner, $namespace, $classification, null, true);
        }

        ksort($nested, SORT_STRING);
        $nested = array_values($nested);
        $notNull = $field->required && $enforced;
        $encrypted = $classification->encrypted();
        $value = $field->options->describeValue($nested);

        return new FieldDescriptor(
            $column === null ? null : self::column($field, $column, $notNull, $encrypted),
            $field->handle,
            $namespace,
            $field->owner,
            $field->options->typeName(),
            $field->label,
            $field->description,
            $field->required,
            $classification,
            $field->agents,
            $field->filterable,
            $field->sortable,
            $encrypted,
            $value->php->withNullable(! $notNull),
            $value->typeScript->withNullable(! $notNull),
            [new ValidationRule($notNull ? ValidationRuleName::Required : ValidationRuleName::Nullable), ...$value->rules],
            $value->choices,
            $nested,
            $field->location,
            $field->options instanceof ShapedOptions ? $field->options->base->typeName() : null,
        );
    }

    private static function column(FieldBlueprint $field, string $name, bool $notNull, bool $encrypted): ColumnDescriptor
    {
        if ($encrypted) {
            return new ColumnDescriptor($name, 'bytea', $notNull, []);
        }

        $shape = $field->options->describeColumn(SqlText::identifier($name));

        return new ColumnDescriptor($name, $shape->type, $notNull, $shape->checks);
    }
}
