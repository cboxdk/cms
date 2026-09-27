<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;

/**
 * The type of a field and the choices that belong to it, such as `max_length` for `text`. The
 * FieldType that reads a field of the type makes them, and they carry the rules of the type that
 * compare their own values, so BlueprintRules checks every field type alike.
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
}
