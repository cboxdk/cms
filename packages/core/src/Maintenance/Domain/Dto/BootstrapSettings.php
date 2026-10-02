<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\RoleHandle;

/**
 * The settings of the one-time access bootstrap (PRD 5.10): the handle of the bootstrap role, from
 * cbox-cms.access.bootstrap_role, and whether the process runs in the production environment, where
 * the bootstrap is refused.
 */
#[Internal]
final readonly class BootstrapSettings
{
    public function __construct(
        public RoleHandle $role,
        public bool $production,
    ) {}
}
