<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Schema\InvalidTypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Override;

/**
 * The testkit's TypeCatalog: the types a test gives it, in memory. A test of kernel code that must
 * work for any type builds the types it needs with TypeDefinition, instead of relying on the
 * application's generated catalog (GUARDRAILS 2.4). Like the generated catalog, it holds each id
 * and each name once, lists the types sorted by name and never changes.
 */
#[Experimental]
final readonly class FakeTypeCatalog implements TypeCatalog
{
    /** @var list<TypeDefinition> sorted by name */
    private array $types;

    /**
     * @throws InvalidTypeDefinition when two types share an id or a name
     */
    public function __construct(TypeDefinition ...$types)
    {
        $byName = [];
        $ids = [];

        foreach ($types as $type) {
            if (isset($byName[$type->name->value])) {
                throw InvalidTypeDefinition::duplicateType($type->name->value);
            }

            if (isset($ids[$type->id->toString()])) {
                throw InvalidTypeDefinition::duplicateType($type->id->toString());
            }

            $byName[$type->name->value] = $type;
            $ids[$type->id->toString()] = true;
        }

        ksort($byName, SORT_STRING);
        $this->types = array_values($byName);
    }

    #[Override]
    public function all(): array
    {
        return $this->types;
    }

    #[Override]
    public function find(TypeId $id): ?TypeDefinition
    {
        return array_find($this->types, static fn (TypeDefinition $type): bool => $type->id->equals($id));
    }

    #[Override]
    public function named(TypeName $name): ?TypeDefinition
    {
        return array_find($this->types, static fn (TypeDefinition $type): bool => $type->name->equals($name));
    }
}
