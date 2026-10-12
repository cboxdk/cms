<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Contract;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantHead;
use Cbox\Cms\Contracts\Content\VariantHeadReader;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryReader;
use Cbox\Cms\Core\Entries\Adapter\PostgresRevisionContents;
use Cbox\Cms\Core\Entries\Adapter\ReaderVariantHeads;
use Cbox\Cms\Core\Entries\Boundary\StoredContent;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Content\VariantHeadReaderContract;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Tests\TestCase;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * The shared VariantHeadReader contract suite against the kernel's reader on Postgres, as the app
 * role in a transaction under the context of a staff member whose region is HOME, over a
 * FakeTypeCatalog with the suite's type (GUARDRAILS 9: the same suite against the fake and the
 * real reader). Each head's entry, revisions, payload and head are written as the superuser: the
 * draft revision with its payload under the type's schema version, or under the next version
 * with no payload for a head given with null fields, and a published revision at the highest
 * number when a release wrote one.
 */
final class PostgresVariantHeadReaderContractTest extends TestCase
{
    use RealPostgres;
    use VariantHeadReaderContract;

    private const int FIRST_REVISION_ID = 700;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $clock = new FakeClock(new DateTimeImmutable(StorageTables::CREATED_AT));
        app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));

        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::HOME, kind: 'site'));
        $superuser->table('changeset_register')->insert(['changeset_id' => StorageTables::CHANGESET, 'retention_class' => 'standard']);

        $actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff)->id;

        DB::connection()->beginTransaction();
        new ActorContext(app(ConnectionResolverInterface::class))->set(new AccessContext(
            new ActorPrincipal($actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [new AccessRegion(new NodePath(StorageTables::label(self::HOME)))],
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
    protected function variantHeadReader(VariantHead ...$heads): VariantHeadReader
    {
        $superuser = StorageTables::superuser();
        $type = self::suiteType();
        $revisionId = self::FIRST_REVISION_ID;

        foreach ($heads as $head) {
            $superuser->table('entries')->insertOrIgnore([
                'id' => $head->entry->toString(), 'type_id' => $type->id->toString(), 'home_node_id' => $head->home->toString(), 'owner_actor_id' => null,
                'lifecycle' => 'active', 'version' => 1, 'created_at' => StorageTables::CREATED_AT,
            ]);

            $draft = $revisionId++;
            $schemaVersion = $head->fields instanceof FieldValues ? $type->version : $type->version + 1;
            $superuser->table('revisions')->insert([
                'revision_id' => $draft, 'entry_id' => $head->entry->toString(), 'variant' => $head->variant->value, 'rev_no' => $head->revision->value, 'kind' => 'draft',
                'schema_version' => $schemaVersion, 'changeset_id' => StorageTables::CHANGESET, 'created_at' => StorageTables::CREATED_AT,
            ]);
            $superuser->table('revision_payloads')->insert([
                'revision_id' => $draft, 'kind' => 'draft', 'format_version' => StoredContent::FORMAT_VERSION,
                'content' => $head->fields instanceof FieldValues ? StoredContent::payload($head->fields) : '{}',
            ]);

            $published = null;

            if ($head->latest->value > $head->revision->value) {
                $published = $revisionId++;
                $superuser->table('revisions')->insert([
                    'revision_id' => $published, 'entry_id' => $head->entry->toString(), 'variant' => $head->variant->value, 'rev_no' => $head->latest->value, 'kind' => 'published',
                    'schema_version' => $schemaVersion, 'changeset_id' => StorageTables::CHANGESET, 'created_at' => StorageTables::CREATED_AT,
                ]);
            }

            $superuser->table('variant_heads')->insert([
                'entry_id' => $head->entry->toString(), 'variant' => $head->variant->value, 'draft_revision_id' => $draft,
                'published_revision_id' => $head->released instanceof RevisionNumber ? $published : null, 'schema_version' => $schemaVersion,
                'release_state' => $head->state->value, 'version' => $head->version->value, 'created_at' => StorageTables::CREATED_AT,
            ]);
        }

        $connections = app(ConnectionResolverInterface::class);

        return new ReaderVariantHeads(new PostgresEntryReader($connections), new PostgresRevisionContents($connections), new FakeTypeCatalog($type));
    }
}
