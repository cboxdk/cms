<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Domain\OperatorGenesis;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeInstallationOperator;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeOperatorGenesis;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * OperatorGenesisBehaviour against the fake the maintenance action tests use.
 */
final class FakeOperatorGenesisBehaviourTest extends TestCase
{
    use OperatorGenesisBehaviour;

    private ?FakeInstallationOperator $shared = null;

    private FakeInstallationOperator $installation {
        get => $this->shared ??= new FakeInstallationOperator;
    }

    #[Override]
    protected function operatorGenesis(): OperatorGenesis
    {
        return new FakeOperatorGenesis($this->installation);
    }

    #[Override]
    protected function genesisWithoutOwner(): OperatorGenesis
    {
        return new FakeOperatorGenesis($this->installation, ownerConnection: false);
    }

    #[Override]
    protected function installedOperator(): ?ActorId
    {
        return $this->installation->operator;
    }
}
