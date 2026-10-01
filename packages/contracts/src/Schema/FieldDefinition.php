<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\FieldTypes\FieldBase;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;

/**
 * A field of a type (PRD 11.12, 12.2) as the kernel needs it at run time, compiled by cms:generate
 * from the blueprints: where it lives, what it holds, how it is classified and who may see it.
 *
 * - `namespace` is null for a field of the type's owner, and the extender's namespace for a field
 *   an extension adds, which code addresses as `ext.<namespace>.<handle>` (address()).
 * - `fieldType` is the field type as the blueprint names it: a core field type such as `text`, or
 *   `<namespace>:<handle>` of a module or addon. `base` is null for a core field type, and for an
 *   addon's field type the core field type whose form its value takes (FieldTypeContribution::shape()),
 *   which valueType() gives for every field: the kernel reads and writes a value in that form.
 * - `classification` decides who may read the field (PRD 12.2), and `agents` whether MCP tools and
 *   agents see it (PRD 2.31): a public or internal field unless its blueprint says no, a
 *   confidential field only when its blueprint says yes, and a personal or sensitive field never.
 * - `encrypted` says the value is stored as ciphertext, and `required`, `filterable` and
 *   `sortable` are what the blueprint declares; an extension field's `required` is enforced when an
 *   entry is published, never when it is written (PRD 11.12, point 1).
 * - A top-level field has its column in the type table. A group's nested fields, in `fields`, have
 *   none, because the group is one column; they take the group's namespace, classification and
 *   encryption, and are sorted by handle.
 */
#[Experimental]
final readonly class FieldDefinition
{
    private const string FIELD_TYPE = '/\A(?:[a-z][a-z0-9]{0,19}:)?[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

    /** @var list<FieldDefinition> sorted by handle */
    public array $fields;

    /**
     * @param  list<FieldDefinition>  $fields  the nested fields of a group, each once
     * @param  ?FieldBase  $base  the core field type an addon's field type takes the form of; null for a core field type
     */
    public function __construct(
        public ?FieldNamespace $namespace,
        public FieldHandle $handle,
        public string $fieldType,
        public ClassificationAccess $classification,
        public bool $agents,
        public bool $encrypted,
        public bool $required,
        public bool $filterable,
        public bool $sortable,
        public ?ColumnDefinition $column,
        array $fields = [],
        public ?FieldBase $base = null,
    ) {
        if (preg_match(self::FIELD_TYPE, $fieldType) !== 1) {
            throw InvalidTypeDefinition::fieldType($fieldType);
        }

        if (str_contains($fieldType, ':') !== $base instanceof FieldBase) {
            throw InvalidTypeDefinition::fieldBase($fieldType);
        }

        if ($agents && ClassificationAccess::Confidential->rank() < $classification->rank()) {
            throw InvalidTypeDefinition::agents($this->address(), $classification);
        }

        $byHandle = [];

        foreach ($fields as $field) {
            if ($field->column instanceof ColumnDefinition) {
                throw InvalidTypeDefinition::nestedColumn($field->handle->value, $this->address());
            }

            if ($field->namespace?->value !== $namespace?->value
                || $field->classification !== $classification
                || $field->encrypted !== $encrypted) {
                throw InvalidTypeDefinition::nestedField($field->handle->value, $this->address());
            }

            if (isset($byHandle[$field->handle->value])) {
                throw InvalidTypeDefinition::duplicateHandle($this->address().'.'.$field->handle->value);
            }

            $byHandle[$field->handle->value] = $field;
        }

        ksort($byHandle, SORT_STRING);
        $this->fields = array_values($byHandle);
    }

    /**
     * Whether a reader with the classification access may read the field (PRD 6.2, 12.2): its
     * classification is at most the access, and for an agent its blueprint opens it to agents
     * (PRD 2.31).
     *
     * @param  bool  $agent  whether the reader's credential was issued for an agent
     */
    public function readableBy(ClassificationAccess $access, bool $agent): bool
    {
        return $access->allows($this->classification) && (! $agent || $this->agents);
    }

    /**
     * The core field type whose form the field's value takes: the field type itself for a core
     * field type, and its base for an addon's.
     */
    public function valueType(): string
    {
        return $this->base instanceof FieldBase ? $this->base->value : $this->fieldType;
    }

    /**
     * How code addresses the field: its handle, or `ext.<namespace>.<handle>` for an extension
     * field (PRD 11.12, point 2).
     */
    public function address(): string
    {
        return $this->namespace instanceof FieldNamespace
            ? 'ext.'.$this->namespace->value.'.'.$this->handle->value
            : $this->handle->value;
    }

    /**
     * The nested field of a group with the handle, or null.
     */
    public function field(FieldHandle $handle): ?self
    {
        return array_find($this->fields, static fn (self $field): bool => $field->handle->equals($handle));
    }
}
