<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The field types that blueprint files may use, from the contributors it is built with
 * (GUARDRAILS 2.4). The reader resolves the `type` of every field here, the core's types included,
 * so a field type the core registers and one another contributor registers are read the same way.
 */
#[Internal]
final readonly class FieldTypeRegistry
{
    /** @var array<string, FieldType> by name, sorted */
    private array $types;

    /**
     * @throws DuplicateFieldType when two field types have the same name
     */
    public function __construct(FieldTypeContributor ...$contributors)
    {
        $types = [];
        $contributorOf = [];

        foreach ($contributors as $contributor) {
            foreach ($contributor->fieldTypes() as $type) {
                $name = $type->name();

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
}
