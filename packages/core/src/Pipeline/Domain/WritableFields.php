<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ClosedValue;

/**
 * Which fields of a revision a writer may set (PRD 2.31, 6.2, 12.2): exactly the fields it may
 * read, by FieldDefinition::readableBy(), the rule TypeDefinition::readable() and ReadableFields
 * apply to every read. A field classified above the writer's classification access is closed to
 * it, and for an agent every field whose blueprint does not open it to agents, and inside a group
 * it may read, every nested field closed to agents, at every depth and in each item of a repeated
 * group.
 *
 * closed() names every value a writer gives for such a field, so the kernel refuses it: a writer
 * never sets what it will not be allowed to read. A null, or no value, sets nothing: kept() then
 * takes every closed field from the content the variant holds, so a revise, which replaces every
 * field, never erases what its writer cannot see. A field the type does not declare is left to the
 * type's validator.
 */
#[Internal]
final readonly class WritableFields
{
    /**
     * Every value in the fields that the writer may not set, with its path below $at.
     *
     * @return list<ClosedValue>
     */
    public static function closed(TypeDefinition $type, FieldValues $fields, ClassificationAccess $access, bool $agent, FieldPath $at): array
    {
        $closed = self::closedInMap($type, null, $fields->own, $access, $agent, $at);

        foreach ($fields->extensions as $extension) {
            array_push($closed, ...self::closedInMap($type, $extension->namespace, $extension->fields, $access, $agent, $at));
        }

        return $closed;
    }

    /**
     * Every value in one field's value that the writer may not set: the field itself, or for an
     * agent the nested fields closed to agents.
     *
     * @return list<ClosedValue>
     */
    public static function closedIn(FieldDefinition $definition, FieldValue $value, ClassificationAccess $access, bool $agent, FieldPath $at): array
    {
        if ($value instanceof NullValue) {
            return [];
        }

        if (! $definition->readableBy($access, $agent)) {
            return [new ClosedValue($at, $definition)];
        }

        if (! $agent || $definition->fields === []) {
            return [];
        }

        $closed = [];

        if ($value instanceof ListValue) {
            foreach ($value->items as $index => $item) {
                array_push($closed, ...self::closedIn($definition, $item, $access, $agent, $at->then($index)));
            }
        }

        if ($value instanceof GroupValue) {
            foreach ($value->fields->fields as $field) {
                $member = $definition->field($field->handle);

                if ($member instanceof FieldDefinition) {
                    array_push($closed, ...self::closedIn($member, $field->value, $access, $agent, $at->then($field->handle->value)));
                }
            }
        }

        return $closed;
    }

    /**
     * Whether the type has a field, or a nested field, the writer may not set, so a revise must
     * take it from the content the variant holds.
     */
    public static function hidesAny(TypeDefinition $type, ClassificationAccess $access, bool $agent): bool
    {
        return array_any($type->fields, static fn (FieldDefinition $field): bool => ! $field->readableBy($access, $agent) || ($agent && self::closesNested($field)));
    }

    /**
     * The written fields with every field closed to the writer as the current content holds it:
     * a closed top-level field takes the current value, or is left out when the current content
     * has none, and for an agent each nested field closed to agents takes the current group's
     * value, item by item in a repeated group. Every other field is the written one.
     */
    public static function kept(TypeDefinition $type, FieldValues $written, FieldValues $current, ClassificationAccess $access, bool $agent): FieldValues
    {
        $namespaces = [];

        foreach ([...$written->extensions, ...$current->extensions] as $extension) {
            $namespaces[$extension->namespace->value] = $extension->namespace;
        }

        $extensions = [];

        foreach ($namespaces as $namespace) {
            $map = self::keptMap($type, $namespace, $written->extension($namespace) ?? new FieldMap, $current->extension($namespace) ?? new FieldMap, $access, $agent);

            if (! $map->isEmpty()) {
                $extensions[] = new ExtensionFields($namespace, $map);
            }
        }

        return new FieldValues(self::keptMap($type, null, $written->own, $current->own, $access, $agent), ...$extensions);
    }

    private static function keptMap(TypeDefinition $type, ?FieldNamespace $namespace, FieldMap $written, FieldMap $current, ClassificationAccess $access, bool $agent): FieldMap
    {
        $kept = [];

        foreach ($written->fields as $field) {
            $definition = $type->field($namespace, $field->handle);

            if (! $definition instanceof FieldDefinition) {
                $kept[] = $field;
            } elseif ($definition->readableBy($access, $agent)) {
                $kept[] = new NamedValue($field->handle, $agent ? self::merged($definition, $field->value, $current->get($field->handle)) : $field->value);
            }
        }

        foreach ($current->fields as $field) {
            $definition = $type->field($namespace, $field->handle);

            if ($definition instanceof FieldDefinition && ! $definition->readableBy($access, $agent)) {
                $kept[] = $field;
            }
        }

        return new FieldMap(...$kept);
    }

    /**
     * The value an agent wrote for a field it may read, with the nested fields closed to agents
     * taken from the current value.
     */
    private static function merged(FieldDefinition $definition, FieldValue $written, ?FieldValue $current): FieldValue
    {
        if ($definition->fields === []) {
            return $written;
        }

        if ($written instanceof ListValue && $current instanceof ListValue) {
            return new ListValue(...array_map(
                static fn (FieldValue $item, int $index): FieldValue => self::merged($definition, $item, $current->items[$index] ?? null),
                $written->items,
                array_keys($written->items),
            ));
        }

        if (! $written instanceof GroupValue || ! $current instanceof GroupValue) {
            return $written;
        }

        $kept = [];

        foreach ($written->fields->fields as $field) {
            $member = $definition->field($field->handle);

            if (! $member instanceof FieldDefinition) {
                $kept[] = $field;
            } elseif ($member->agents) {
                $kept[] = new NamedValue($field->handle, self::merged($member, $field->value, $current->fields->get($field->handle)));
            }
        }

        foreach ($current->fields->fields as $field) {
            $member = $definition->field($field->handle);

            if ($member instanceof FieldDefinition && ! $member->agents) {
                $kept[] = $field;
            }
        }

        return new GroupValue(new FieldMap(...$kept));
    }

    private static function closesNested(FieldDefinition $field): bool
    {
        return array_any($field->fields, static fn (FieldDefinition $member): bool => ! $member->agents || self::closesNested($member));
    }

    /**
     * @return list<ClosedValue>
     */
    private static function closedInMap(TypeDefinition $type, ?FieldNamespace $namespace, FieldMap $map, ClassificationAccess $access, bool $agent, FieldPath $at): array
    {
        $closed = [];

        foreach ($map->fields as $field) {
            $definition = $type->field($namespace, $field->handle);

            if ($definition instanceof FieldDefinition) {
                array_push($closed, ...self::closedIn($definition, $field->value, $access, $agent, $at->then(...explode('.', $definition->address()))));
            }
        }

        return $closed;
    }
}
