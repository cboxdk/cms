<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A role as a grant command reads it (PRD 5.10, 12.2): its classification ceiling, the command and
 * query names it may run, sorted, and its version.
 */
#[Internal]
final readonly class StoredRole
{
    /**
     * @param  list<CommandName>  $permissions
     */
    public function __construct(
        public RoleId $id,
        public ClassificationAccess $ceiling,
        public array $permissions,
        public AggregateVersion $version,
    ) {}
}
