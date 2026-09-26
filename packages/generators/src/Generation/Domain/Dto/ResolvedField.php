<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\ColumnName;
use Cbox\Cms\Generators\Schema\Domain\Dto\AddonOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\FieldBlueprint;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\Owner;

/**
 * A top-level field of a type: the owner's own field, or an extension field of the namespace of its
 * extender (PRD 11.12). The generated code addresses an owner's field by its handle and an
 * extension field as `ext.<namespace>.<handle>`, from handle() and $namespace.
 *
 * $name is the field's column in the type table: the handle, or `ext__<namespace>__<handle>` for
 * an extension field. Handles have no double underscore and namespaces no underscore, so the
 * encoding is injective and never gives the column of one of the owner's fields; the resolver
 * keeps the fields of a type unique and sorted by it.
 */
#[Internal]
final readonly class ResolvedField
{
    /**
     * @param  string  $name  the column: the handle, or `ext__<namespace>__<handle>` for an extension field
     * @param  ?Owner  $namespace  the extender, or null for a field of the type's owner
     */
    public function __construct(
        public string $name,
        public FieldBlueprint $blueprint,
        public ?Owner $namespace,
    ) {}

    public static function own(FieldBlueprint $blueprint): self
    {
        return new self($blueprint->handle->value, $blueprint, null);
    }

    /**
     * An extension field, with its column name.
     */
    public static function extension(FieldBlueprint $blueprint): self
    {
        return new self(self::columnName($blueprint->owner, $blueprint->handle), $blueprint, $blueprint->owner);
    }

    /**
     * The column of an extension field in its type table: `ext__<namespace>__<handle>`.
     */
    public static function columnName(Owner $namespace, Handle $handle): string
    {
        return ColumnName::ofExtensionField($namespace, $handle)->value;
    }

    /**
     * The field's handle, without the extender's namespace.
     */
    public function handle(): string
    {
        return $this->blueprint->handle->value;
    }

    /**
     * The field type as the blueprint file writes it, such as `text` or `acme:colour`.
     */
    public function typeName(): string
    {
        return $this->blueprint->options->typeName();
    }

    /**
     * Whether the field type is an addon's `<namespace>:<handle>` rather than a core field type.
     */
    public function hasAddonType(): bool
    {
        return $this->blueprint->options instanceof AddonOptions;
    }
}
