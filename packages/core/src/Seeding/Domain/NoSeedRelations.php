<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;
use Override;
use Random\Randomizer;

/**
 * No targets at all: the relations of the fields generated without a data set to draw from are
 * left out.
 */
#[Internal]
final readonly class NoSeedRelations implements SeedRelations
{
    #[Override]
    public function pick(array $types, Randomizer $random): ?EntryId
    {
        return null;
    }

    #[Override]
    public function any(Randomizer $random): ?EntryId
    {
        return null;
    }
}
