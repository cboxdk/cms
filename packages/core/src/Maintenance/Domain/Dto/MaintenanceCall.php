<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Pipeline\Command;

/**
 * A maintenance command to run as the installation operator: the command and the unit of work its
 * idempotency key is derived from. A maintenance command names a stable unit, such as
 * `sites:<handle>:<hash of the configured locales>` or `staff:<sha256 of the lowercased email>`,
 * so a rerun of the same work replays the first run's receipt.
 */
#[Internal]
final readonly class MaintenanceCall
{
    public function __construct(
        public Command $command,
        public UnitOfWork $unitOfWork,
    ) {}
}
