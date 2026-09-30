<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Placements\Domain\PlacementSlugRef;

/**
 * A slug a command gives a placement, and whether another placement that is not withdrawn has it
 * below the same node in the same locale (invariant 15).
 */
#[Internal]
final readonly class SlugClaim
{
    public function __construct(
        public PlacementSlugRef $slug,
        public bool $taken,
    ) {}

    /**
     * The slug as an aggregate read: at version 1 when taken, absent when free.
     */
    public function read(): ReadVersion
    {
        return $this->taken ? ReadVersion::at($this->slug, new AggregateVersion(1)) : ReadVersion::absent($this->slug);
    }
}
