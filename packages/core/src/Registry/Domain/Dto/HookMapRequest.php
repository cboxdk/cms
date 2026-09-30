<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * Which command cms:hooks maps (PRD 13.2): every version of the command with the name.
 */
#[Experimental]
final readonly class HookMapRequest
{
    public function __construct(public CommandName $command) {}
}
