<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;

/**
 * Who changed a variant's head last, and when (PRD 5.4, 6.1), as entry.get gives it: the actor of
 * the changeset that last gave the head its version, the time of that changeset in UTC, and the
 * actor's display name when the reader may read the actor's profile, which is their own or, with
 * access to personal data, one a role of theirs that may run actor.list lets them list (PRD 12.2);
 * null otherwise, so a reader who may not see the name still sees that someone else saved.
 */
#[Experimental]
final readonly class HeadChange
{
    public function __construct(
        public ActorId $actor,
        public ?DisplayName $name,
        public DateTimeImmutable $at,
    ) {}
}
