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
 * the field types it contributes with the class of their contributor, the types it owns and the
 * types it extends, each list sorted by name. The field types and own types are in the addon's
 * namespace, and it extends no type of its own. It has a field type contributor exactly when it
 * has field types; cms:generate makes it and registers the field types (PRD 11.12).
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

    public ?string $fieldTypeContributor;

    /**
     * @param  list<ContributedFieldType>  $fieldTypes  sorted by name
     * @param  list<TypeName>  $types  sorted by name
     * @param  list<TypeName>  $extends  sorted by name
     * @param  ?string  $fieldTypeContributor  the class of the field types' contributor, without a leading backslash
     */
    public function __construct(
        public AddonNamespace $namespace,
        string $package,
        array $fieldTypes,
        array $types,
        array $extends,
        ?string $fieldTypeContributor = null,
    ) {
        $this->package = InvalidRegistryEntry::checkPackage($package);
        $this->fieldTypes = $this->check($namespace, 'field types', $fieldTypes, static fn (ContributedFieldType $type): string => $type->value, static fn (ContributedFieldType $type): bool => $type->namespace === $namespace->value);
        $this->types = $this->check($namespace, 'types', $types, static fn (TypeName $type): string => $type->value, static fn (TypeName $type): bool => $type->owner === $namespace->value);
        $this->extends = $this->check($namespace, 'extended types', $extends, static fn (TypeName $type): string => $type->value, static fn (TypeName $type): bool => $type->owner !== $namespace->value);

        if (($fieldTypes === []) !== ($fieldTypeContributor === null)) {
            throw InvalidRegistryEntry::because(sprintf(
                'Addon "%s" has %s. An addon has a field type contributor exactly when it contributes field types.',
                $namespace->value,
                $fieldTypes === [] ? 'a field type contributor but no field types' : 'field types but no field type contributor',
            ));
        }

        $this->fieldTypeContributor = $fieldTypeContributor === null ? null : InvalidRegistryEntry::checkClass('field type contributor', $fieldTypeContributor);
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
