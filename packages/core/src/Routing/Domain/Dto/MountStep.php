<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * The route reached a mount (PRD 5.8): the slug is looked up below its source, and the placement
 * found there is shown as it is, identical to the source, whose placement stays canonical.
 */
#[Experimental]
final readonly class MountStep
{
    public function __construct(
        public NodeId $mount,
        public NodeId $source,
    ) {}
}
