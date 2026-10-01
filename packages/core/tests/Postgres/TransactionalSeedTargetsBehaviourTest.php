<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Seeding\Adapter\TransactionalSeedTargets;
use Cbox\Cms\Core\Seeding\Domain\SeedTargets;
use Cbox\Cms\Core\Tests\Seeding\SeedTargetsBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;

/**
 * SeedTargetsBehaviour against TransactionalSeedTargets on real Postgres, as the app role: the
 * nodes and entries are written as the superuser, and the targets read them in a transaction of their own
 * under the context, which they roll back. It refuses a connection that is in a transaction.
 */
final class TransactionalSeedTargetsBehaviourTest extends TestCase
{
    use RealPostgres;
    use SeedTargetsBehaviour;

    private ?ActorId $actor = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $root = StorageTables::label(self::ROOT);
        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert([
            StorageTables::node(self::ROOT, kind: 'site'),
            StorageTables::node(self::OUTSIDE, kind: 'site'),
        ]);
        $superuser->table('nodes')->insert([
            StorageTables::node(self::FIRST, self::ROOT, $root),
            StorageTables::node(self::SECOND, self::ROOT, $root),
        ]);
        $superuser->table('nodes')->insert([
            StorageTables::node(self::MOUNT, self::ROOT, $root, 'mount', self::FIRST),
            StorageTables::node(self::DEEP, self::SECOND, $root.'.'.StorageTables::label(self::SECOND)),
        ]);
        $superuser->table('entries')->insert([
            array_merge(StorageTables::entry(self::IN_FIRST), ['home_node_id' => self::FIRST]),
            array_merge(StorageTables::entry(self::IN_DEEP), ['home_node_id' => self::DEEP]),
            array_merge(StorageTables::entry(self::IN_OUTSIDE), ['home_node_id' => self::OUTSIDE]),
        ]);

        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $this->actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Service)->id;
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Test]
    public function it_refuses_a_connection_that_is_in_a_transaction_and_leaves_no_transaction_open(): void
    {
        $access = new AccessContext(new ActorPrincipal($this->actor(), [], IssuerKind::Service, ClassificationAccess::Sensitive), [], ClassificationAccess::Internal);
        $this->seedTargets()->nodes($access);
        $this->seedTargets()->existing($access, [EntryId::fromString(self::IN_FIRST)]);

        self::assertSame(0, DB::connection()->transactionLevel());

        DB::connection()->beginTransaction();

        try {
            try {
                $this->seedTargets()->existing($access, [EntryId::fromString(self::IN_FIRST)]);
                self::fail('existing() read inside the caller\'s transaction.');
            } catch (LogicException $refused) {
                self::assertStringContainsString('already in a transaction', $refused->getMessage());
            }

            $this->expectException(LogicException::class);
            $this->seedTargets()->nodes($access);
        } finally {
            DB::connection()->rollBack();
        }
    }

    #[Override]
    protected function seedTargets(): SeedTargets
    {
        return new TransactionalSeedTargets(app(ConnectionResolverInterface::class));
    }

    #[Override]
    protected function actor(): ActorId
    {
        return $this->actor ?? throw new LogicException('setUp() adds the actor.');
    }
}
