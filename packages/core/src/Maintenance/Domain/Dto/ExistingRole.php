<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;

/**
 * A role that has the bootstrap role's handle already (PRD 5.10): its id, its ceiling and its
 * permissions, sorted.
 */
#[Internal]
final readonly class ExistingRole
{
    /**
     * @param  list<CommandName>  $permissions
     */
    public function __construct(
        public RoleId $id,
        public ClassificationAccess $ceiling,
        public array $permissions,
    ) {}
}
