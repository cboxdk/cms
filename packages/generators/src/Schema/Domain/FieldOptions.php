<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;

/**
 * The type of a field and the choices that belong to it, such as `max_length` for `text`. The
 * FieldType that reads a field of the type makes them, and they carry the rules of the type that
 * compare their own values, so BlueprintRules checks every field type alike. They also say how a
 * value of the type is stored and typed, which DescriptorCompiler puts in the type descriptor
 * (PRD 11.12), so a field type an addon contributes is compiled like the core's.
 */
#[Internal]
interface FieldOptions
{
    /**
     * The field type as the blueprint file writes it, the name of its FieldType, such as `text`.
     */
    public function typeName(): string;

    /**
     * The problems of the type's rules that compare the options' values with each other, such as a
     * `min` above the `max`, each named below the field's location.
     *
     * @return list<GenerationProblem>
     */
    public function problems(SourceLocation $field): array;

    /**
     * The fields nested in the field, which are a namespace of their own for handles and are
     * checked like any other field: the fields of a group. Empty for a type without nested fields.
     *
     * @return list<FieldBlueprint>
     */
    public function nestedFields(): array;

    /**
     * The Postgres type of the column that holds a value of the field in its type table, and the
     * CHECK expressions over that column, which is given as a quoted identifier (PRD 11.6). The
     * compiler asks only for a top-level field that is not encrypted.
     */
    public function describeColumn(string $column): ColumnShape;

    /**
     * The value's PHP and TypeScript types without null, the rules of its runtime validator besides
     * `required` and `nullable`, and its choices (PRD 11.12). $fields are the compiled nested fields
     * of the field, sorted by handle, from which a group builds its shape; empty for another type.
     *
     * @param  list<FieldDescriptor>  $fields
     */
    public function describeValue(array $fields): ValueShape;
}
