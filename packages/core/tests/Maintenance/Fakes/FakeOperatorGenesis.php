<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance\Fakes;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Domain\Dto\Genesis;
use Cbox\Cms\Core\Maintenance\Domain\Dto\InstalledOperator;
use Cbox\Cms\Core\Maintenance\Domain\InstallRefused;
use Cbox\Cms\Core\Maintenance\Domain\OperatorGenesis;
use Override;

/**
 * The genesis in memory: it names the operator in the FakeInstallationOperator it shares with the
 * action under test, once. Without the owner connection it refuses as the Postgres genesis does.
 * Every genesis it wrote is in $written.
 */
final class FakeOperatorGenesis implements OperatorGenesis
{
    /** @var list<Genesis> */
    public array $written = [];

    public function __construct(
        private readonly FakeInstallationOperator $installation,
        private readonly bool $ownerConnection = true,
    ) {}

    #[Override]
    public function install(Genesis $genesis): InstalledOperator
    {
        if (! $this->ownerConnection) {
            throw InstallRefused::ownerConnectionMissing();
        }

        if ($this->installation->operator instanceof ActorId) {
            return new InstalledOperator($this->installation->operator, false);
        }

        $this->written[] = $genesis;
        $this->installation->operator = $genesis->operator;

        return new InstalledOperator($genesis->operator, true);
    }
}
