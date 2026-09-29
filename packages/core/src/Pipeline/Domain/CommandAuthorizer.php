<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Authorization;

/**
 * Phase 2 of the command pipeline (PRD 6.2): whether the call's AccessContext may run the command
 * on the aggregates the resolve phase read. It only looks: it reads nothing it was not given,
 * writes nothing, and a refusal carries the reason in plain language. Authorization hooks may add
 * refusals to the kernel's decision, never grant what it refuses.
 */
#[Internal]
interface CommandAuthorizer
{
    public function authorize(AccessContext $access, CommandName $command, Command $input, Aggregates $aggregates): Authorization;
}
