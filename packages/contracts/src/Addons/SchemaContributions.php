<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Schema\TypeName;

/**
 * What an addon adds to the schema (PRD 13.1, 13.3, 11.12): the field types it contributes, the
 * types it owns, and the types of others it extends with blueprint extensions in its own
 * namespace. The blueprint files of its types and extensions are in its schema directory, an
 * absolute path, which it must have when it owns or extends a type.
 *
 * AddonManifest holds each list to the addon's namespace: its field types and types are
 * `<namespace>:<handle>` in it, and the types it extends belong to another owner, because an
 * owner does not extend its own type (generate_extension_of_own_type). Each list is sorted, and a
 * name in it twice is refused.
 */
#[Experimental]
final readonly class SchemaContributions
{
    /** @var list<ContributedFieldType> sorted by name */
    public array $fieldTypes;

    /** @var list<TypeName> sorted by name */
    public array $types;

    /** @var list<TypeName> sorted by name */
    public array $extends;

    public ?string $directory;

    /**
     * @param  list<ContributedFieldType>  $fieldTypes
     * @param  list<TypeName>  $types  the types the addon owns
     * @param  list<TypeName>  $extends  the types of others the addon extends
     * @param  string|null  $directory  the absolute directory of the addon's blueprint files
     *
     * @throws InvalidAddonManifest when a name is listed twice, or the directory is missing or not absolute
     */
    public function __construct(
        array $fieldTypes = [],
        array $types = [],
        array $extends = [],
        ?string $directory = null,
    ) {
        $this->fieldTypes = $this->sorted('field type', array_map(static fn (ContributedFieldType $type): string => $type->value, $fieldTypes), $fieldTypes);
        $this->types = $this->sorted('type', array_map(static fn (TypeName $type): string => $type->value, $types), $types);
        $this->extends = $this->sorted('extended type', array_map(static fn (TypeName $type): string => $type->value, $extends), $extends);

        if ($directory === null && ($types !== [] || $extends !== [])) {
            throw InvalidAddonManifest::because('The addon owns or extends types but names no schema directory. Give the absolute directory of its blueprint files, built from __DIR__.');
        }

        $this->directory = $directory === null ? null : ClassNames::absoluteDirectory('schema', $directory);
    }

    public function isEmpty(): bool
    {
        return $this->fieldTypes === [] && $this->types === [] && $this->extends === [];
    }

    /**
     * @template T of object
     *
     * @param  list<string>  $names  the name of each item
     * @param  list<T>  $items
     * @return list<T>
     *
     * @throws InvalidAddonManifest when a name is listed twice
     */
    private function sorted(string $what, array $names, array $items): array
    {
        $byName = [];

        foreach ($items as $index => $item) {
            if (array_key_exists($names[$index], $byName)) {
                throw InvalidAddonManifest::because(sprintf('The %s "%s" is listed twice.', $what, $names[$index]));
            }

            $byName[$names[$index]] = $item;
        }

        ksort($byName, SORT_STRING);

        return array_values($byName);
    }
}
