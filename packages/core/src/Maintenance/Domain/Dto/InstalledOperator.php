<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * The installation operator after cms:install: its id, and whether this run created it (false when
 * the installation had one already, which the run left as it was).
 */
#[Internal]
final readonly class InstalledOperator
{
    public function __construct(
        public ActorId $operator,
        public bool $created,
    ) {}
}
