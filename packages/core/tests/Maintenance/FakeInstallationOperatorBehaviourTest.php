<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Domain\InstallationOperator;
use Cbox\Cms\Core\Tests\Maintenance\Fakes\FakeInstallationOperator;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * InstallationOperatorBehaviour against the fake the maintenance action tests use.
 */
final class FakeInstallationOperatorBehaviourTest extends TestCase
{
    use InstallationOperatorBehaviour;

    private FakeInstallationOperator $operator {
        get => $this->operatorFake ??= new FakeInstallationOperator;
    }

    private ?FakeInstallationOperator $operatorFake = null;

    #[Override]
    protected function installationOperator(): InstallationOperator
    {
        return $this->operator;
    }

    #[Override]
    protected function installWith(ActorId $operator): void
    {
        $this->operator->operator = $operator;
    }
}
