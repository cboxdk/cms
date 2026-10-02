<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Maintenance\Domain\Dto\Genesis;
use Cbox\Cms\Core\Maintenance\Domain\Dto\InstalledOperator;

/**
 * Writes the genesis of an installation (PRD 5.16, 6.5 invariant 37) in one transaction of its own,
 * as the owner role: the operator, a service actor registered and activated at once, the row of
 * `installation` that names it, and the genesis changeset, in which the operator is its own actor,
 * with its audit row and the events actor.registered and actor.activated. It is the only place an
 * actor becomes active without a command run by an active actor before it.
 *
 * Once an installation has an operator it writes nothing and answers with that operator, also when
 * another install committed first while this one ran.
 */
#[Internal]
interface OperatorGenesis
{
    /**
     * @throws InstallRefused when the process has no owner connection, or the connection named is
     *                        not the owner role's; nothing is written
     * @throws PartitionMissing when no partition covers the genesis changeset's time; nothing is
     *                          written
     */
    public function install(Genesis $genesis): InstalledOperator;
}
