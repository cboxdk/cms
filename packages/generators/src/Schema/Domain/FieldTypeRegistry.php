<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;

/**
 * The field types that blueprint files may use, from the contributors it is built with
 * (GUARDRAILS 2.4). The reader resolves the `type` of every field here, the core's types included,
 * so a field type the core registers and one another contributor registers are read the same way.
 *
 * Names are held to PRD 11.12 and 13.1 as they are registered: CoreFieldTypes registers bare
 * handles, and every other contributor registers `<namespace>:<handle>` in the namespace of its
 * owner, which is never `app` or `ext`. So no contributor takes another's name or a name that a
 * later core field type could take.
 */
#[Internal]
final readonly class FieldTypeRegistry
{
    /** @var array<string, FieldType> by name, sorted */
    private array $types;

    /**
     * @throws InvalidFieldTypeName when a contributor names a field type outside its own namespace
     * @throws DuplicateFieldType when two field types have the same name
     */
    public function __construct(FieldTypeContributor ...$contributors)
    {
        $types = [];
        $contributorOf = [];

        foreach ($contributors as $contributor) {
            $owner = $this->ownerOf($contributor);

            foreach ($contributor->fieldTypes() as $type) {
                $name = $type->name();
                $this->checkName($name, $contributor, $owner);

                if (array_key_exists($name, $types)) {
                    throw DuplicateFieldType::named($name, $contributorOf[$name], $contributor);
                }

                $types[$name] = $type;
                $contributorOf[$name] = $contributor;
            }
        }

        ksort($types, SORT_STRING);
        $this->types = $types;
    }

    /**
     * The field type a blueprint file names, or null when no contributor registered it.
     */
    public function find(string $name): ?FieldType
    {
        return $this->types[$name] ?? null;
    }

    /**
     * The names of the registered field types, sorted.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->types));
    }

    /**
     * The namespace the contributor registers its field types in, or null for the core.
     *
     * @throws InvalidFieldTypeName when the namespace is not one the contributor may use
     */
    private function ownerOf(FieldTypeContributor $contributor): ?Owner
    {
        $owner = $contributor->owner();

        if (! $owner instanceof Owner) {
            if (! $contributor instanceof CoreFieldTypes) {
                throw InvalidFieldTypeName::withoutNamespace($contributor);
            }

            return null;
        }

        // Owner refuses `ext` itself; `app` is an owner of definitions but never of field types.
        if ($owner->equals(Owner::app())) {
            throw InvalidFieldTypeName::reservedNamespace($contributor, $owner);
        }

        return $owner;
    }

    /**
     * @throws InvalidFieldTypeName when the name is not a bare handle for the core, or not
     *                              `<namespace>:<handle>` in the contributor's own namespace
     */
    private function checkName(string $name, FieldTypeContributor $contributor, ?Owner $owner): void
    {
        if (! $owner instanceof Owner) {
            if (preg_match(Handle::PATTERN, $name) !== 1) {
                throw InvalidFieldTypeName::coreName($name);
            }

            return;
        }

        $prefix = $owner->value.':';

        if (! str_starts_with($name, $prefix) || preg_match(Handle::PATTERN, substr($name, strlen($prefix))) !== 1) {
            throw InvalidFieldTypeName::outsideNamespace($name, $contributor, $owner);
        }
    }
}
