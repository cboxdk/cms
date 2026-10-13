<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;

/**
 * A service actor cms:service:create registered and activated: its id, and the changesets of
 * actor.register and actor.activate.
 */
#[Internal]
final readonly class RegisteredService
{
    public function __construct(
        public ActorId $actor,
        public ChangesetId $registered,
        public ChangesetId $activated,
    ) {}
}
