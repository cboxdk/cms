<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Subscriptions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * The actor a subscription runs as in one round of the runner (PRD 6.5 invariant 21), the one its
 * Delivery names, with the access context its batches run under.
 */
#[Internal]
final readonly class SubscriberIdentity
{
    public function __construct(
        public ActorId $actor,
        public AccessContext $context,
    ) {}
}
