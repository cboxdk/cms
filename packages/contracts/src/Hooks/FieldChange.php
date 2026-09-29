<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Hooks;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;

/**
 * One change a TransformHook asks for (PRD 6.2 phase 4): the new value of a top-level field of the
 * revision the plan creates for a variant. The field is the owner's when the namespace is null,
 * and the extender's under its namespace otherwise. A group field is set as a whole.
 */
#[Experimental]
final readonly class FieldChange
{
    public function __construct(
        public VariantRef $variant,
        public ?FieldNamespace $namespace,
        public FieldHandle $handle,
        public FieldValue $value,
    ) {}

    /**
     * A change of a field of the type's owner.
     */
    public static function own(VariantRef $variant, FieldHandle $handle, FieldValue $value): self
    {
        return new self($variant, null, $handle, $value);
    }

    /**
     * A change of a field an extender adds under its namespace.
     */
    public static function extension(VariantRef $variant, FieldNamespace $namespace, FieldHandle $handle, FieldValue $value): self
    {
        return new self($variant, $namespace, $handle, $value);
    }

    /**
     * How code addresses the field: its handle, or `ext.<namespace>.<handle>` (PRD 11.12).
     */
    public function address(): string
    {
        return $this->namespace instanceof FieldNamespace
            ? 'ext.'.$this->namespace->value.'.'.$this->handle->value
            : $this->handle->value;
    }
}
