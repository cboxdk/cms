<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Schema\Domain\SourceLocation;

/**
 * A field of a type descriptor, compiled from its blueprint (PRD 11.12): everything the generators
 * need, so none of them reads the blueprint.
 *
 * A top-level field has a column; a field inside a group has none, because the group is one JSONB
 * column, and it has the classification of its group (PRD 12.2). $namespace is the extender of an
 * extension field, or null for a field of the type's owner, as in ResolvedField. $required is what
 * the blueprint says; whether the value may be null follows from it only where the owner's code
 * enforces it, which it never does for an extension field (PRD 11.12, point 1). $location is where
 * the blueprint declares the field, for the problems a generator reports; the canonical JSON leaves
 * it out.
 *
 * $base is the core field type whose form the value takes: the field type itself for a core field
 * type, and for an addon's field type the base of its shape (FieldTypeContribution::shape()). The generators
 * write a value by its base and name the field's type by $type, so an addon's field type is written
 * as its base is.
 */
#[Internal]
final readonly class FieldDescriptor
{
    public string $base;

    /**
     * @param  string  $type  the field type as the blueprint writes it, such as `text`
     * @param  bool  $encrypted  whether the value is stored as ciphertext (PRD 12.2)
     * @param  list<ValidationRule>  $validation  `required` or `nullable` first
     * @param  list<SelectOption>  $choices  the options of a select field, in the order of the file
     * @param  list<FieldDescriptor>  $fields  the nested fields of a group, sorted by handle
     * @param  ?string  $base  the core field type the value takes the form of; null for $type itself
     */
    public function __construct(
        public ?ColumnDescriptor $column,
        public Handle $handle,
        public ?Owner $namespace,
        public Owner $owner,
        public string $type,
        public string $label,
        public ?string $description,
        public bool $required,
        public Classification $classification,
        public bool $agents,
        public bool $filterable,
        public bool $sortable,
        public bool $encrypted,
        public PhpType $php,
        public TypeScriptType $typeScript,
        public array $validation,
        public array $choices,
        public array $fields,
        public SourceLocation $location,
        ?string $base = null,
    ) {
        $this->base = $base ?? $type;
    }
}
