<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\RoleHandle;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapState;

/**
 * What the one-time access bootstrap reads (PRD 5.10, 5.16), under the access context of the
 * installation operator: whether any staff actor holds a grant, whether the node exists, and the
 * role with the bootstrap role's handle. It reads past the operator's regions, which reach nothing,
 * and only as the operator.
 */
#[Internal]
interface AccessBootstrapState
{
    public function read(AccessContext $operator, NodeId $node, RoleHandle $role): BootstrapState;
}
