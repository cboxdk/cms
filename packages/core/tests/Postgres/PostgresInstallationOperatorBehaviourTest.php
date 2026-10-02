<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Core\Maintenance\Adapter\PostgresInstallationOperator;
use Cbox\Cms\Core\Maintenance\Adapter\PostgresOperatorGenesis;
use Cbox\Cms\Core\Maintenance\Domain\Dto\Genesis;
use Cbox\Cms\Core\Maintenance\Domain\InstallationOperator;
use Cbox\Cms\Core\Tests\Maintenance\InstallationOperatorBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * InstallationOperatorBehaviour against PostgresInstallationOperator on real Postgres, as the app
 * role, which reads no row of `installation` itself: the install is the genesis on the owner
 * connection.
 */
final class PostgresInstallationOperatorBehaviourTest extends TestCase
{
    use InstallationOperatorBehaviour;
    use RealPostgres;

    private const string AT = '2026-03-10T12:00:00Z';

    /** A changeset id in the millisecond of AT. */
    private const string CHANGESET = '019cd79e-4600-7000-8000-0000000005b2';

    #[Override]
    protected function installationOperator(): InstallationOperator
    {
        return new PostgresInstallationOperator(app(ConnectionResolverInterface::class));
    }

    #[Override]
    protected function installWith(ActorId $operator): void
    {
        $clock = new FakeClock(new DateTimeImmutable(self::AT));
        app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));

        new PostgresOperatorGenesis(app(ConnectionResolverInterface::class), $clock, config()->string('cbox-cms.database.owner_connection'))
            ->install(new Genesis($operator, ChangesetId::fromString(self::CHANGESET), $clock->now(), new CorrelationId('install')));
    }
}
