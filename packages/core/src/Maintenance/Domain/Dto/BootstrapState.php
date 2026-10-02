<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * What the one-time access bootstrap reads before it runs a command (PRD 5.10): whether any staff
 * actor holds a grant, ended or not, whether the node exists, and the role with the bootstrap
 * role's handle, or null when no role has it.
 */
#[Internal]
final readonly class BootstrapState
{
    public function __construct(
        public bool $staffGranted,
        public bool $nodeExists,
        public ?ExistingRole $role,
    ) {}
}
