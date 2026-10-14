<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Random\Randomizer;

/**
 * The targets a seeded relation may name (PRD 5.11): an entry of one of the types, drawn with the
 * entry's randomizer, so the same seed names the same targets, or null when there is none. A link
 * to an entry takes an entry of any type.
 */
#[Internal]
interface SeedRelations
{
    /**
     * @param  list<TypeName>  $types
     */
    public function pick(array $types, Randomizer $random): ?EntryId;

    /**
     * An entry of any type, the target of a link to an entry (B2-T76), or null when there is none.
     */
    public function any(Randomizer $random): ?EntryId;
}
