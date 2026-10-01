<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
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
     * The fields of an entry of the type as a reader with the classification access may see them
     * (PRD 6.2, 12.2): every field classified above the access is left out, and every field the
     * type does not declare, because what the kernel cannot classify it does not hand out. An
     * extender whose fields are all left out is left out too. A group is one top-level field with
     * one classification, so it is kept or left out whole.
     *
     * For an agent it also leaves out every field whose blueprint does not open it to agents
     * (FieldDefinition::readableBy(), PRD 2.31), and inside a group it keeps, the nested fields its
     * blueprint closes to them, in the group's value and in each item of a repeated group's, at
     * every depth.
     *
     * @param  bool  $agent  whether the reader's credential was issued for an agent
     */
    public function readable(FieldValues $fields, ClassificationAccess $access, bool $agent): FieldValues
    {
        $extensions = [];

        foreach ($fields->extensions as $extension) {
            $allowed = $this->readableMap($extension->namespace, $extension->fields, $access, $agent);

            if (! $allowed->isEmpty()) {
                $extensions[] = new ExtensionFields($extension->namespace, $allowed);
            }
        }

        return new FieldValues($this->readableMap(null, $fields->own, $access, $agent), ...$extensions);
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

    private function readableMap(?FieldNamespace $namespace, FieldMap $fields, ClassificationAccess $access, bool $agent): FieldMap
    {
        $allowed = [];

        foreach ($fields->fields as $field) {
            $definition = $this->field($namespace, $field->handle);

            if ($definition instanceof FieldDefinition && $definition->readableBy($access, $agent)) {
                $allowed[] = $agent ? new NamedValue($field->handle, self::forAgents($definition, $field->value)) : $field;
            }
        }

        return new FieldMap(...$allowed);
    }

    /**
     * The value of a field agents see, with the nested fields of a group that agents do not see
     * left out, in the group's value and in each item of a repeated group's.
     */
    private static function forAgents(FieldDefinition $definition, FieldValue $value): FieldValue
    {
        if ($definition->fields === []) {
            return $value;
        }

        if ($value instanceof ListValue) {
            return new ListValue(...array_map(static fn (FieldValue $item): FieldValue => self::forAgents($definition, $item), $value->items));
        }

        if (! $value instanceof GroupValue) {
            return $value;
        }

        $nested = [];

        foreach ($value->fields->fields as $field) {
            $member = $definition->field($field->handle);

            if ($member instanceof FieldDefinition && $member->agents) {
                $nested[] = new NamedValue($field->handle, self::forAgents($member, $field->value));
            }
        }

        return new GroupValue(new FieldMap(...$nested));
    }
}
