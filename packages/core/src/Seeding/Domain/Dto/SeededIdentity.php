<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * The id and the type of a seeded entry, which follow from its index alone.
 */
#[Internal]
final readonly class SeededIdentity
{
    public function __construct(
        public EntryId $id,
        public SeedableType $type,
    ) {}
}
