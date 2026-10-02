<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A role as role.list gives it (PRD 5.10, 12.2): its id, handle, classification ceiling, the
 * command and query names its permissions name, sorted, and its version.
 */
#[Experimental]
final readonly class ListedRole
{
    /**
     * @param  list<CommandName>  $permissions
     */
    public function __construct(
        public RoleId $id,
        public RoleHandle $handle,
        public ClassificationAccess $ceiling,
        public array $permissions,
        public AggregateVersion $version,
    ) {}
}
