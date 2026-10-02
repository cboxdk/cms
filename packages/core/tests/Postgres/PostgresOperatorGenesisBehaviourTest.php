<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Maintenance\Adapter\PostgresInstallationOperator;
use Cbox\Cms\Core\Maintenance\Adapter\PostgresOperatorGenesis;
use Cbox\Cms\Core\Maintenance\Domain\OperatorGenesis;
use Cbox\Cms\Core\Tests\Maintenance\OperatorGenesisBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * OperatorGenesisBehaviour against PostgresOperatorGenesis on real Postgres: the genesis on the
 * owner connection, read back as the app role through cms_installation_operator(), with the
 * partitions of the genesis' day covered.
 */
final class PostgresOperatorGenesisBehaviourTest extends TestCase
{
    use OperatorGenesisBehaviour;
    use RealPostgres;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        app(PartitionFixtures::class)->coverClock($this->clock(), new DateInterval('P1D'));
    }

    #[Override]
    protected function operatorGenesis(): OperatorGenesis
    {
        return new PostgresOperatorGenesis(app(ConnectionResolverInterface::class), $this->clock(), config()->string('cbox-cms.database.owner_connection'));
    }

    #[Override]
    protected function genesisWithoutOwner(): OperatorGenesis
    {
        return new PostgresOperatorGenesis(app(ConnectionResolverInterface::class), $this->clock(), null);
    }

    #[Override]
    protected function installedOperator(): ?ActorId
    {
        return new PostgresInstallationOperator(app(ConnectionResolverInterface::class))->find();
    }

    private function clock(): FakeClock
    {
        return new FakeClock(new DateTimeImmutable(self::GENESIS_AT));
    }
}
