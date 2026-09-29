<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Registry\Domain\InvalidRegistryEntry;

/**
 * An addon's schema contributions in the registry (PRD 13.3): the addon's namespace and package,
 * the field types it contributes, the types it owns and the types it extends, each list sorted by
 * name. The field types and own types are in the addon's namespace, and it extends no type of its
 * own.
 */
#[Experimental]
final readonly class SchemaEntry
{
    public string $package;

    /** @var list<ContributedFieldType> */
    public array $fieldTypes;

    /** @var list<TypeName> */
    public array $types;

    /** @var list<TypeName> */
    public array $extends;

    /**
     * @param  list<ContributedFieldType>  $fieldTypes  sorted by name
     * @param  list<TypeName>  $types  sorted by name
     * @param  list<TypeName>  $extends  sorted by name
     */
    public function __construct(
        public AddonNamespace $namespace,
        string $package,
        array $fieldTypes,
        array $types,
        array $extends,
    ) {
        $this->package = InvalidRegistryEntry::checkPackage($package);
        $this->fieldTypes = $this->check($namespace, 'field types', $fieldTypes, static fn (ContributedFieldType $type): string => $type->value, static fn (ContributedFieldType $type): bool => $type->namespace === $namespace->value);
        $this->types = $this->check($namespace, 'types', $types, static fn (TypeName $type): string => $type->value, static fn (TypeName $type): bool => $type->owner === $namespace->value);
        $this->extends = $this->check($namespace, 'extended types', $extends, static fn (TypeName $type): string => $type->value, static fn (TypeName $type): bool => $type->owner !== $namespace->value);
    }

    /**
     * @template T of object
     *
     * @param  list<T>  $items
     * @param  callable(T): string  $name
     * @param  callable(T): bool  $owned  whether the item's owner is the right one
     * @return list<T>
     */
    private function check(AddonNamespace $namespace, string $what, array $items, callable $name, callable $owned): array
    {
        $values = array_map($name, $items);
        $sorted = array_values(array_unique($values));
        sort($sorted, SORT_STRING);

        if ($sorted !== $values) {
            throw InvalidRegistryEntry::because(sprintf('The %s of addon "%s" are %s. Each is listed once, sorted by name.', $what, $namespace->value, implode(', ', $values)));
        }

        foreach ($items as $item) {
            if (! $owned($item)) {
                throw InvalidRegistryEntry::because(sprintf(
                    'Addon "%s" lists "%s" among its %s. Its field types and types are in its namespace, and the types it extends are not.',
                    $namespace->value,
                    $name($item),
                    $what,
                ));
            }
        }

        return $items;
    }
}
