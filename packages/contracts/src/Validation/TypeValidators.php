<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Validation;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\TypeId;

/**
 * The runtime validators of an installation's types, by type (PRD 11.8, 11.12): cms:generate
 * writes one TypeValidator per type and a class that implements this contract with all of them,
 * and the generated service provider binds it. The kernel finds the validator of a type here after
 * it has found the type in the TypeCatalog, so it validates a revision's fields against the rules
 * of the schema version the code was generated from (invariant 4); a surface finds the same
 * validator for the input it takes.
 *
 * Like the catalog, it is fixed for the life of the process, reads nothing and is cheap to ask.
 * It has one validator for every type of the TypeCatalog and none for another.
 */
#[Experimental]
interface TypeValidators
{
    /**
     * Every validator, sorted by the id of its type, each type once.
     *
     * @return list<TypeValidator>
     */
    public function all(): array;

    /**
     * The validator of the type with the id, or null when the installation has no such type.
     */
    public function find(TypeId $id): ?TypeValidator;
}
