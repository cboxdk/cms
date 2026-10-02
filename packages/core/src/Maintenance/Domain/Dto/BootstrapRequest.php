<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;

/**
 * A run of the one-time access bootstrap (PRD 5.10, 5.16): the active staff actor that gets the
 * bootstrap role, and the node it gets it on.
 */
#[Internal]
final readonly class BootstrapRequest
{
    public function __construct(
        public ActorId $actor,
        public NodeId $node,
    ) {}
}
