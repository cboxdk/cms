<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of actor.me (PRD 5.16, 13.4): the principal's own actor with its id, class, state and
 * version, its profile, or null when the actor has none, and the grants it holds in the order of
 * their ids. The profile is the subject's own, so it reaches the subject whatever its
 * classification access: a person always sees their own name and email on the who-am-I page.
 */
#[Experimental]
final readonly class ActorMe implements Result
{
    /**
     * @param  list<OwnGrant>  $grants
     */
    public function __construct(
        public ActorId $actor,
        public ActorClass $class,
        public ActorState $state,
        public AggregateVersion $version,
        public ?ActorProfile $profile,
        public array $grants,
    ) {}
}
