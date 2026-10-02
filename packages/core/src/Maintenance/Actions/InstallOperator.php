<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Maintenance\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Core\Maintenance\Domain\Dto\Genesis;
use Cbox\Cms\Core\Maintenance\Domain\Dto\InstalledOperator;
use Cbox\Cms\Core\Maintenance\Domain\InstallationOperator;
use Cbox\Cms\Core\Maintenance\Domain\InstallRefused;
use Cbox\Cms\Core\Maintenance\Domain\OperatorGenesis;

/**
 * Creates the installation operator once (PRD 5.16, 3.3), the action behind cms:install. An
 * installation that has an operator keeps it: the action writes nothing and answers with it, so a
 * second run, on any deploy, changes nothing. Otherwise it writes the genesis through the
 * OperatorGenesis as the owner role: a new service actor, registered and activated in one changeset
 * in which it is its own actor, at the Clock's time.
 *
 * It runs in the maintenance process, from the console, outside any transaction.
 */
#[Internal]
final readonly class InstallOperator
{
    public function __construct(
        private InstallationOperator $installation,
        private OperatorGenesis $genesis,
        private IdGenerator $ids,
        private Clock $clock,
    ) {}

    /**
     * @throws InstallRefused when the process has no owner connection, or it is not the owner role's
     * @throws PartitionMissing when no partition covers the genesis changeset's time
     */
    public function install(): InstalledOperator
    {
        $existing = $this->installation->find();

        if ($existing instanceof ActorId) {
            return new InstalledOperator($existing, false);
        }

        return $this->genesis->install(new Genesis(
            new ActorId($this->ids->next()),
            new ChangesetId($this->ids->next()),
            $this->clock->now(),
            new CorrelationId($this->ids->next()->value),
        ));
    }
}
