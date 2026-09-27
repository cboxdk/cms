<?php

declare(strict_types=1);

namespace Examples\Postgres\Harness;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a test on AddonTestCase gets: it runs as the app role with no transaction around it, its
 * writes commit, and it starts with empty tables and no keys, because the harnesses truncate the
 * tables and remove the run's keys after each test.
 */
final class RealServicesTest extends AddonTestCase
{
    #[Test]
    public function a_write_commits_and_another_connection_sees_it(): void
    {
        $clock = new FakeClock(new DateTimeImmutable('2031-05-01T09:00:00Z'));
        app()->instance(Clock::class, $clock);
        app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
        $changesetId = new ChangesetId(new FakeIdGenerator(clock: $clock)->next());

        self::assertSame(0, DB::transactionLevel());
        self::assertSame(0, DB::table('receipts')->count());

        app(ReceiptStore::class)->store(new StoredReceipt($changesetId, RetentionClass::Standard));

        [$other] = app(IndependentConnections::class)->open(1);
        self::assertSame(1, $other->table('receipts')->where('changeset_id', $changesetId->toString())->count());
    }

    #[Test]
    public function the_test_runs_as_the_app_role_which_has_no_ddl(): void
    {
        // SQLSTATE 42501, insufficient_privilege: the app role has USAGE on the schema, not CREATE.
        $this->expectException(QueryException::class);
        $this->expectExceptionCode('42501');

        Schema::create('notes', static function (Blueprint $table): void {
            $table->id();
        });
    }

    #[Test]
    public function redis_keys_land_under_the_run_prefix(): void
    {
        $run = app(ValkeyRun::class);

        Redis::set('greeting', 'hello');

        self::assertSame('hello', Redis::get('greeting'));
        self::assertSame([$run->prefix.'greeting'], $run->keys());
    }
}
