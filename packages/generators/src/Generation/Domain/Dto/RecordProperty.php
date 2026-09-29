<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;

/**
 * A field as a property of a generated record class (PRD 11.12): the property's camelCase name, its
 * native type without null and the PHPDoc type when the native type says less, how the record
 * reads the field from a FieldReader named `$fields`, and how it writes the field's generic value.
 */
#[Internal]
final readonly class RecordProperty
{
    /**
     * @param  string  $native  the native type without null, such as `string` or a class in the record's namespace
     * @param  ?string  $doc  the PHPDoc type without null, such as `list<TagsChoice>`, or null when the native type is enough
     * @param  string  $read  the expression that reads the value from `$fields`
     * @param  string  $write  the expression that turns `$this-><name>` into its FieldValue
     * @param  list<string>  $imports  the classes the expressions and the type use, fully qualified
     */
    public function __construct(
        public FieldDescriptor $field,
        public string $name,
        public string $native,
        public ?string $doc,
        public string $read,
        public string $write,
        public array $imports,
    ) {}

    public function nullable(): bool
    {
        return $this->field->php->nullable;
    }

    /**
     * The native type as a declaration, with `?` when the value may be null.
     */
    public function declaration(): string
    {
        return ($this->nullable() ? '?' : '').$this->native;
    }

    /**
     * The PHPDoc type with `|null` when the value may be null, or null when the native type is enough.
     */
    public function docType(): ?string
    {
        return $this->doc === null ? null : $this->doc.($this->nullable() ? '|null' : '');
    }
}
