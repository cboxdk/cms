<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Schema;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\TypeId;

/**
 * The types of an installation, as the kernel knows them at run time (PRD 11.12, GUARDRAILS 2.4).
 * The kernel knows no type by name: cms:generate compiles the blueprints into a catalog class in
 * the application's generated code, and the generated service provider binds this contract to it.
 * A type comes with the descriptor data the kernel needs to write its type table, its capabilities,
 * and each field's classification and agents flag.
 *
 * A catalog is fixed for the life of the process: it changes only with a new deploy of generated
 * code. It never reads the blueprints or the database, and each call is cheap.
 */
#[Experimental]
interface TypeCatalog
{
    /**
     * Every type, sorted by name, each id and each name once.
     *
     * @return list<TypeDefinition>
     */
    public function all(): array;

    /**
     * The type with the id, or null when the installation has none.
     */
    public function find(TypeId $id): ?TypeDefinition;

    /**
     * The type with the name, `<owner>:<handle>`, or null when the installation has none.
     */
    public function named(TypeName $name): ?TypeDefinition;
}
