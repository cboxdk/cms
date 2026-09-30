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
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryReader;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Tests\Entries\EntryReaderBehaviour;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Tests\TestCase;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * EntryReaderBehaviour against PostgresEntryReader on real Postgres, as the app role inside a
 * transaction under the actor context of a staff member whose region is the root ROOT above NODE.
 * The rows are written as the superuser: the head of ENTRY points at its revision 4 in
 * `revisions`, or, for a head of a type without revisions, has no draft revision and keeps the
 * number with its snapshot in `head_snapshots`; a released head also points at its published
 * revision 5.
 */
final class PostgresEntryReaderBehaviourTest extends TestCase
{
    use EntryReaderBehaviour;
    use RealPostgres;

    private const string ROOT = '0192a0c0-0000-7000-8000-0000000002a1';

    private const int REVISION = 40;

    private const int PUBLISHED = 41;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert([...StorageTables::node(self::NODE, self::ROOT, StorageTables::label(self::ROOT)), 'version' => 3]);
        $superuser->table('nodes')->insert(StorageTables::node(self::OTHER_NODE));
        $superuser->table('entries')->insert([
            ['id' => self::ENTRY, 'type_id' => self::TYPE, 'home_node_id' => self::NODE, 'owner_actor_id' => null, 'lifecycle' => 'active', 'version' => 2, 'created_at' => StorageTables::CREATED_AT],
            ['id' => self::BARE_ENTRY, 'type_id' => self::TYPE, 'home_node_id' => self::NODE, 'owner_actor_id' => null, 'lifecycle' => 'active', 'version' => 1, 'created_at' => StorageTables::CREATED_AT],
        ]);
        $superuser->table('changeset_register')->insert(['changeset_id' => StorageTables::CHANGESET, 'retention_class' => 'standard']);
        $superuser->table('revisions')->insert([
            'revision_id' => self::REVISION, 'entry_id' => self::ENTRY, 'variant' => 'shared', 'rev_no' => 4, 'kind' => 'draft',
            'schema_version' => 1, 'changeset_id' => StorageTables::CHANGESET, 'created_at' => StorageTables::CREATED_AT,
        ]);
        $superuser->table('variant_heads')->insert([
            'entry_id' => self::ENTRY, 'variant' => 'shared', 'draft_revision_id' => self::REVISION, 'schema_version' => 1,
            'release_state' => 'unreleased', 'version' => 5, 'created_at' => StorageTables::CREATED_AT,
        ]);

        $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
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
    protected function entryReader(bool $snapshots = false, ?string $released = null): EntryReader
    {
        $superuser = StorageTables::superuser();
        $superuser->table('head_snapshots')->delete();
        $superuser->table('variant_heads')->where('entry_id', self::ENTRY)->update([
            'draft_revision_id' => $snapshots ? null : self::REVISION,
            'published_revision_id' => null,
            'release_state' => 'unreleased',
        ]);
        $superuser->table('revisions')->where('revision_id', self::PUBLISHED)->delete();

        if ($released !== null) {
            $superuser->table('revisions')->insert([
                'revision_id' => self::PUBLISHED, 'entry_id' => self::ENTRY, 'variant' => 'shared', 'rev_no' => 5, 'kind' => 'published',
                'schema_version' => 1, 'changeset_id' => StorageTables::CHANGESET, 'created_at' => StorageTables::CREATED_AT,
            ]);
            $superuser->table('variant_heads')->where('entry_id', self::ENTRY)->update(['published_revision_id' => self::PUBLISHED, 'release_state' => $released]);
        }

        if ($snapshots) {
            $superuser->table('head_snapshots')->insert([
                'entry_id' => self::ENTRY, 'variant' => 'shared', 'rev_no' => 4, 'schema_version' => 1, 'format_version' => 1,
                'content' => '{}', 'updated_at' => StorageTables::CREATED_AT,
            ]);
        }

        return new PostgresEntryReader(app(ConnectionResolverInterface::class));
    }
}
