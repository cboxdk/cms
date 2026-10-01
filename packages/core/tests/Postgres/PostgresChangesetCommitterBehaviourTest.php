<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\ChangesetCommitterBehaviour;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyWorld;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * ChangesetCommitterBehaviour against PostgresChangesetCommitter as the app role on real
 * Postgres, in the container's command transaction, with the tally's version lock and writer and
 * the projection TallyWorld::PROJECTION pending for every tally.raised event.
 */
final class PostgresChangesetCommitterBehaviourTest extends TestCase
{
    use ChangesetCommitterBehaviour;
    use RealPostgres;

    /** The table uncover() drops the world's partition of: the first one a commit writes. */
    private const string UNCOVERED_TABLE = 'changesets';

    private ?TallyWorld $world = null;

    private ?ChangesetCommitter $committer = null;

    #[Override]
    protected function tearDown(): void
    {
        TallyWorld::cleanUp();

        parent::tearDown();
    }

    #[Override]
    protected function committer(): ChangesetCommitter
    {
        return $this->committer ??= $this->world()->committer();
    }

    #[Override]
    protected function commandTransaction(): CommandTransaction
    {
        return app(CommandTransaction::class);
    }

    #[Override]
    protected function actor(): ActorId
    {
        return $this->world()->actor;
    }

    #[Override]
    protected function uncover(): void
    {
        DB::connection('pgsql_owner')->statement(sprintf('drop table if exists %s_p%s', self::UNCOVERED_TABLE, $this->world()->clock->now()->format('Ymd')));
    }

    #[Override]
    protected function cover(): void
    {
        app(PartitionFixtures::class)->coverClock($this->world()->clock, new DateInterval('P1D'));
    }

    private function world(): TallyWorld
    {
        return $this->world ??= new TallyWorld;
    }
}
