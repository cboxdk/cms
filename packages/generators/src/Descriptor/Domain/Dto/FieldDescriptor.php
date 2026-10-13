<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Descriptor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Validation\Condition;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\WhenHidden;
use Cbox\Cms\Generators\Schema\Domain\Classification;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldDefault;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldUi;
use Cbox\Cms\Generators\Schema\Domain\Dto\RelationOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\FieldFallback;
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
 *
 * $translatable says which variant holds the field in a type with localization variants (PRD 10.1),
 * a language's when true and the shared one when false, and $fallback how a translatable field
 * falls back (PRD 10.3); a field inside a group has its group's, and in a type without localization
 * both have their defaults.
 *
 * The presentation keys (gap G2), $labels and $descriptions per locale, $ui and $default, reach
 * only the editor descriptor; the canonical JSON, the catalog, the codecs, the validators and the
 * tables leave them out.
 *
 * $when is the condition a conditional field is shown on, with its references counted in groups
 * up (FieldReference), and $whenHidden what happens to its value while it is hidden (gap G3). A
 * conditional field is never NOT NULL and its types are nullable, because a hidden field holds no
 * value; its presence() holds while it is shown.
 *
 * $relation is what a relation field holds beside its form (PRD 5.11), null for every other field:
 * a relation whose targets carry attributes takes the form of a group, so its $base is `group`
 * and its $fields are `entry` and the attributes.
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
     * @param  bool  $translatable  whether a language's variant holds the field
     * @param  FieldFallback  $fallback  how a translatable field falls back
     * @param  array<string, string>  $labels  the label by locale, in file order, when the blueprint gives a map
     * @param  array<string, string>  $descriptions  the description by locale, in file order, when the blueprint gives a map
     * @param  ?FieldUi  $ui  the hints the form takes, or null
     * @param  ?FieldDefault  $default  the form's starting value, or null
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
        public bool $translatable = false,
        public FieldFallback $fallback = FieldFallback::Variant,
        public array $labels = [],
        public array $descriptions = [],
        public ?FieldUi $ui = null,
        public ?FieldDefault $default = null,
        public ?Condition $when = null,
        public ?WhenHidden $whenHidden = null,
        public ?RelationDescriptor $relation = null,
    ) {
        $this->base = $base ?? $type;
    }

    /**
     * What the field's generated validator requires while the field is shown: Required for a
     * field the blueprint requires and the owner's code enforces, which a top-level extension field
     * never is, so it is RequiredOnRelease (PRD 11.12, point 1), and Optional for every other field.
     */
    public function presence(): Presence
    {
        if (! $this->required) {
            return Presence::Optional;
        }

        return $this->column instanceof ColumnDescriptor && $this->namespace instanceof Owner ? Presence::RequiredOnRelease : Presence::Required;
    }

    /**
     * The attributes each target of a relation carries, the nested fields but `entry`; empty for
     * a relation without attributes and for every other field.
     *
     * @return list<FieldDescriptor>
     */
    public function relationMeta(): array
    {
        if ($this->relation?->meta !== true) {
            return [];
        }

        return array_values(array_filter($this->fields, static fn (self $field): bool => $field->handle->value !== RelationOptions::ENTRY));
    }
}
