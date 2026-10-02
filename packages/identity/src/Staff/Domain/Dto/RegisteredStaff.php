<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Staff\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * A registered local staff member: the actor, active, with its local account bound.
 */
#[Internal]
final readonly class RegisteredStaff
{
    public function __construct(public ActorId $actor) {}
}
