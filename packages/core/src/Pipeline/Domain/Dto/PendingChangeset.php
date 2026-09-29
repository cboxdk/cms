<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Plan;

/**
 * What the command pipeline hands the commit (PRD 6.2 phase 7): the command's name, version and
 * input, the envelope, the access context, the authorized and validated plan, and every aggregate
 * the call read with its version: the actor and its on-behalf-of chain and the action's reads. The
 * commit checks all of them (invariants 11 and 37).
 */
#[Internal]
final readonly class PendingChangeset
{
    public function __construct(
        public CommandName $command,
        public int $version,
        public Command $input,
        public Envelope $envelope,
        public AccessContext $access,
        public Plan $plan,
        public ReadVersions $reads,
    ) {}
}
