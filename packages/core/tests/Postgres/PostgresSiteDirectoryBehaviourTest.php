<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Core\Structure\Adapter\PostgresSiteDirectory;
use Cbox\Cms\Core\Structure\Domain\SiteDirectory;
use Cbox\Cms\Core\Tests\Structure\SiteDirectoryBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Override;

/**
 * SiteDirectoryBehaviour against PostgresSiteDirectory on real Postgres, as the app role outside
 * any transaction and without an actor context, as cms:sites:sync reads: the lookups run as the
 * owner role. The sites are written by the testkit's structure fixtures as the owner role.
 */
final class PostgresSiteDirectoryBehaviourTest extends TestCase
{
    use RealPostgres;
    use SiteDirectoryBehaviour;

    private ?PostgresStructureFixtures $fixtures = null;

    #[Override]
    protected function siteDirectory(): SiteDirectory
    {
        return new PostgresSiteDirectory(app(ConnectionResolverInterface::class));
    }

    #[Override]
    protected function registerSite(string $handle, array $locales): array
    {
        $clock = new FakeClock;
        $this->fixtures ??= new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 6, clock: $clock));
        $site = $this->fixtures->site($handle, $locales);

        return [$site->id, $site->root->id];
    }
}
