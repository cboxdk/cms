<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Entries\Adapter\PostgresRevisionContents;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\RevisionContentsBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * RevisionContentsBehaviour against PostgresRevisionContents on real Postgres, as the app role
 * inside a transaction under the actor context of a staff member whose region is the root ROOT
 * above the entry's home NODE. The rows are written as the superuser: the two revisions of ENTRY,
 * drafts, each with its payload in `revision_payloads`, and the head of SNAPSHOT_ENTRY with its
 * snapshot in `head_snapshots`.
 */
final class PostgresRevisionContentsBehaviourTest extends TestCase
{
    use RealPostgres;
    use RevisionContentsBehaviour;

    private const string ROOT = '0192a0c0-0000-7000-8000-0000000003a1';

    private const string NODE = '0192a0c0-0000-7000-8000-0000000003a2';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));

        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert(StorageTables::node(self::NODE, self::ROOT, StorageTables::label(self::ROOT)));
        $superuser->table('entries')->insert([
            'id' => self::ENTRY, 'type_id' => '0192a0c0-0000-7000-8000-0000000003f1', 'home_node_id' => self::NODE, 'owner_actor_id' => null,
            'lifecycle' => 'active', 'version' => 1, 'created_at' => StorageTables::CREATED_AT,
        ]);
        $superuser->table('changeset_register')->insert(['changeset_id' => StorageTables::CHANGESET, 'retention_class' => 'standard']);

        $superuser->table('entries')->insert([
            'id' => self::SNAPSHOT_ENTRY, 'type_id' => '0192a0c0-0000-7000-8000-0000000003f1', 'home_node_id' => self::NODE, 'owner_actor_id' => null,
            'lifecycle' => 'active', 'version' => 1, 'created_at' => StorageTables::CREATED_AT,
        ]);
        $superuser->table('variant_heads')->insert(StorageTables::head(['entry_id' => self::SNAPSHOT_ENTRY, 'draft_revision_id' => null, 'schema_version' => 3]));
        $superuser->table('head_snapshots')->insert([
            'entry_id' => self::SNAPSHOT_ENTRY, 'variant' => 'shared', 'rev_no' => 4, 'schema_version' => 3, 'format_version' => 1,
            'content' => (string) json_encode(['title' => self::TITLE]), 'updated_at' => StorageTables::CREATED_AT,
        ]);

        foreach ([1 => 3, 2 => 2] as $number => $schemaVersion) {
            $superuser->table('revisions')->insert([
                'revision_id' => 50 + $number, 'entry_id' => self::ENTRY, 'variant' => 'shared', 'rev_no' => $number, 'kind' => 'draft',
                'schema_version' => $schemaVersion, 'changeset_id' => StorageTables::CHANGESET, 'created_at' => StorageTables::CREATED_AT,
            ]);
            $superuser->table('revision_payloads')->insert([
                'revision_id' => 50 + $number, 'kind' => 'draft', 'format_version' => 1, 'content' => (string) json_encode(['title' => self::TITLE]),
            ]);
        }

        $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff)->id;

        DB::connection()->beginTransaction();
        new ActorContext(app(ConnectionResolverInterface::class))->set(new AccessContext(
            new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [new AccessRegion(new NodePath(StorageTables::label(self::ROOT)))],
            ClassificationAccess::Internal,
        ));
    }

    #[Override]
    protected function tearDown(): void
    {
        DB::connection()->rollBack();
        DB::purge(StorageTables::SUPERUSER);

        parent::tearDown();
    }

    #[Override]
    protected function revisionContents(): RevisionContents
    {
        return new PostgresRevisionContents(app(ConnectionResolverInterface::class));
    }
}
