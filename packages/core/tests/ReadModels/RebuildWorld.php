<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\ReadModels;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\ReadModels\Actions\RebuildReadModels;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildReport;
use Cbox\Cms\Core\ReadModels\Domain\Dto\RebuildRequest;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/**
 * A rebuild of the workbench's type tables on real Postgres (PRD 4.1, invariant 22), next to an
 * EntryWorld whose commands wrote the rows: an active service actor with a role granted on the
 * EntryWorld's root, or the node a test gives, named in cbox-cms.rebuild.service_actor, the container's RebuildReadModels,
 * and the type table's rows read as the superuser, past row level security, and truncated as the
 * owner role.
 */
final readonly class RebuildWorld
{
    public ActorId $actor;

    /**
     * @param  NodeId|null  $granted  the node whose subtree the service actor is granted, the EntryWorld's root by default
     */
    public function __construct(EntryWorld $entries, int $chunkSize = 100, ?NodeId $granted = null, int $seed = 4646)
    {
        $ids = new FakeIdGenerator(seed: $seed, clock: $entries->clock);
        $this->actor = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $entries->clock, $ids)->addActor(ActorClass::Service)->id;
        $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $entries->clock, $ids);
        $fixtures->grant($this->actor, $fixtures->role('rebuilder_'.$seed, ClassificationAccess::Sensitive), $granted ?? NodeId::fromString(EntryWorld::ROOT));

        config(['cbox-cms.rebuild.service_actor' => $this->actor->toString(), 'cbox-cms.rebuild.chunk_size' => $chunkSize]);
    }

    /**
     * The n-th entry id of a test's own, after EntryWorld::ENTRY's.
     */
    public static function entry(int $number): EntryId
    {
        return EntryId::fromString(sprintf('0192a0c0-0000-7000-8000-%012x', 0x100000 + $number));
    }

    public function rebuild(string $type, string $run = 'rebuild-1'): RebuildReport
    {
        return app(RebuildReadModels::class)->rebuild(new RebuildRequest(new TypeName($type), $run));
    }

    /**
     * The service actor's context, as the rebuild resolves it.
     */
    public function access(): AccessContext
    {
        return app(AccessContexts::class)->for(new ActorPrincipal($this->actor, [], IssuerKind::Service, ClassificationAccess::Sensitive));
    }

    /**
     * Every row of the type's table, each column by name, in key order, read as the superuser.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(string $type): array
    {
        $rows = [];

        foreach (StorageTables::superuser()->table(new TypeName($type)->table())->orderBy('cms_entry_id')->orderBy('cms_locale')->orderBy('cms_stage')->get() as $row) {
            $values = [];

            foreach (get_object_vars($row) as $column => $value) {
                $values[(string) $column] = $value;
            }

            $rows[] = $values;
        }

        return $rows;
    }

    /**
     * Removes every row of the types' tables as the owner role, which TRUNCATE is not held back
     * from by row level security.
     */
    public static function truncate(string ...$types): void
    {
        DB::connection('pgsql_owner')->statement(sprintf(
            'truncate table %s',
            implode(', ', array_map(static fn (string $type): string => '"'.new TypeName($type)->table().'"', $types)),
        ));
    }
}
