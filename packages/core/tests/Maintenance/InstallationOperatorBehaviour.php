<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Maintenance;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Domain\InstallationOperator;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every InstallationOperator does, the fake and the Postgres one alike: none before an install,
 * and the operator the install wrote after it, on every read.
 */
trait InstallationOperatorBehaviour
{
    public const string INSTALLED = '01936f5e-8a2b-7c3d-9e4f-0000000005b1';

    abstract protected function installationOperator(): InstallationOperator;

    /**
     * Writes an installation whose operator is $operator, as cms:install would.
     */
    abstract protected function installWith(ActorId $operator): void;

    #[Test]
    public function it_finds_no_operator_before_an_install(): void
    {
        Assert::assertNull($this->installationOperator()->find());
    }

    #[Test]
    public function it_finds_the_operator_the_install_wrote_on_every_read(): void
    {
        $this->installWith(ActorId::fromString(self::INSTALLED));
        $operator = $this->installationOperator();

        Assert::assertEquals(ActorId::fromString(self::INSTALLED), $operator->find());
        Assert::assertEquals(ActorId::fromString(self::INSTALLED), $operator->find(), 'A second read gives the same operator.');
    }
}
