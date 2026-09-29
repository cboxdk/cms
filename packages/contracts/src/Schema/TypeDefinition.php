<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Ids\TypeId;

/**
 * A type as the kernel knows it at run time (PRD 11.2, 11.12, GUARDRAILS 2.4): only from the code
 * cms:generate writes from the blueprints, read through the TypeCatalog.
 *
 * It holds the type's id, which never changes, its name, the owner's version of its definition and
 * the version of each extender's namespace, which together are the type's composite version
 * (PRD 11.12, point 5), its capabilities, and its top-level fields: the owner's and every
 * extender's, each with its column in the type table, sorted by column name.
 */
#[Experimental]
final readonly class TypeDefinition
{
    /** @var list<ExtensionVersion> sorted by namespace */
    public array $extensions;

    /** @var list<FieldDefinition> sorted by column name */
    public array $fields;

    /**
     * @param  int  $version  the owner's version of the definition, from 1
     * @param  list<ExtensionVersion>  $extensions  each namespace once
     * @param  list<FieldDefinition>  $fields  the top-level fields, each with its own column
     */
    public function __construct(
        public TypeId $id,
        public TypeName $name,
        public int $version,
        public TypeCapabilities $capabilities,
        array $extensions,
        array $fields,
    ) {
        if ($version < 1) {
            throw InvalidTypeDefinition::version('the type '.$name->value, $version);
        }

        $byNamespace = [];

        foreach ($extensions as $extension) {
            if (isset($byNamespace[$extension->namespace->value])) {
                throw InvalidTypeDefinition::duplicateNamespace($extension->namespace->value);
            }

            $byNamespace[$extension->namespace->value] = $extension;
        }

        ksort($byNamespace, SORT_STRING);
        $this->extensions = array_values($byNamespace);

        $byColumn = [];
        $addresses = [];

        foreach ($fields as $field) {
            if (! $field->column instanceof ColumnDefinition) {
                throw InvalidTypeDefinition::topLevelColumn($field->address());
            }

            if ($field->namespace instanceof FieldNamespace && ! isset($byNamespace[$field->namespace->value])) {
                throw InvalidTypeDefinition::unknownNamespace($field->address());
            }

            if (isset($addresses[$field->address()])) {
                throw InvalidTypeDefinition::duplicateHandle($field->address());
            }

            if (isset($byColumn[$field->column->name])) {
                throw InvalidTypeDefinition::duplicateColumn($field->column->name);
            }

            $addresses[$field->address()] = true;
            $byColumn[$field->column->name] = $field;
        }

        ksort($byColumn, SORT_STRING);
        $this->fields = array_values($byColumn);
    }

    /**
     * The owner's field with the handle when $namespace is null, or the field the extender with the
     * namespace adds; null when there is none.
     */
    public function field(?FieldNamespace $namespace, FieldHandle $handle): ?FieldDefinition
    {
        return array_find(
            $this->fields,
            static fn (FieldDefinition $field): bool => $field->handle->equals($handle) && $field->namespace?->value === $namespace?->value,
        );
    }

    /**
     * The version of the extender's namespace, or null when it does not extend the type.
     */
    public function extensionVersion(FieldNamespace $namespace): ?int
    {
        return array_find(
            $this->extensions,
            static fn (ExtensionVersion $extension): bool => $extension->namespace->equals($namespace),
        )?->version;
    }
}
