<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Access\Adapter\PostgresCommandAuthorizer;
use Cbox\Cms\Core\Access\Domain\AdministrativePermissions;
use Cbox\Cms\Core\Access\Domain\EscalationGuard;
use Cbox\Cms\Core\Access\Domain\PermissionCatalog;
use Cbox\Cms\Core\Access\Domain\PermissionRule;
use Cbox\Cms\Core\Entries\Actions\CreateEntryAction;
use Cbox\Cms\Core\Entries\Actions\CreateLocalizedEntryAction;
use Cbox\Cms\Core\Entries\Actions\ReleaseEntryVariantAction;
use Cbox\Cms\Core\Entries\Actions\ReleaseVariantAction;
use Cbox\Cms\Core\Entries\Actions\ReviseEntryAction;
use Cbox\Cms\Core\Entries\Actions\ReviseVariantsAction;
use Cbox\Cms\Core\Entries\Actions\VariantReleasePlanner;
use Cbox\Cms\Core\Entries\Adapter\EntryCreatedWriter;
use Cbox\Cms\Core\Entries\Adapter\HeadMovedWriter;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryReader;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryVersionLock;
use Cbox\Cms\Core\Entries\Adapter\PostgresRevisionContents;
use Cbox\Cms\Core\Entries\Adapter\PostgresVariantVersionLock;
use Cbox\Cms\Core\Entries\Adapter\RevisionCreatedWriter;
use Cbox\Cms\Core\Entries\Adapter\VariantReleasedWriter;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateLocalizedEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseEntryVariant;
use Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseVariants;
use Cbox\Cms\Core\Entries\Domain\Dto\LocaleFields;
use Cbox\Cms\Core\Entries\Domain\Dto\VariantRevision;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Adapter\ConnectionCommandTransaction;
use Cbox\Cms\Core\Pipeline\Adapter\PostgresChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Adapter\SavepointRefusal;
use Cbox\Cms\Core\Pipeline\Domain\AffectedProjections;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\Placements\Adapter\PostgresPlacementReader;
use Cbox\Cms\Core\Placements\Adapter\ReaderPublicPlacements;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Core\Structure\Adapter\PostgresNodeVersionLock;
use Cbox\Cms\Core\Subscriptions\Adapter\SystemPacing;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The real command pipeline on Postgres for entry.create, entry.revise and variant.release, each in
 * version 1 and version 2 (PRD 5.4, 5.6, 6.2, 10.3), on the default connection or the one named: the command transaction, the
 * Postgres idempotency and receipt stores, the actor directory, the entry reader, the revision
 * contents a release is validated against, and the PostgresChangesetCommitter with the locks of
 * actors, entries, variants and nodes and the writers of the entry mutations, all on
 * that connection. The types and their validators are the workbench's generated ones. Fakes, so a test decides
 * what they answer: the authorizer, which allows, unless a test grants the actor roles with
 * granted(), which switches to the PostgresCommandAuthorizer over them, and the content
 * hasher. The hooks are none, unless a test gives the world the compiled registry's.
 *
 * The world's structure, written by seed() as the superuser, is the site root ROOT with the section
 * HOME below it; the call's access context reaches the whole tree. The clock stands at NOW, or at
 * the time a test gives the world and seed(), whose day and the next the partitions cover. Each
 * world adds an active staff member as its actor, so two worlds are two actors.
 */
final class EntryWorld
{
    public const string NOW = '2026-03-10T12:00:00.000000+00:00';

    public const string ROOT = '0192a0c0-0000-7000-8000-0000000001a1';

    public const string HOME = '0192a0c0-0000-7000-8000-0000000001a2';

    /** A node no row has. */
    public const string NOWHERE = '0192a0c0-0000-7000-8000-0000000001a9';

    public const string ENTRY = '0192a0c0-0000-7000-8000-0000000001e1';

    /** The type of the workbench with full history and a draft that is released. */
    public const string ARTICLE = 'app:fixture_article';

    /** The type of the workbench with neither history nor stages. */
    public const string MEASUREMENT = 'app:fixture_measurement';

    /** @var list<string> the tables a create or a revise writes, besides the type tables */
    public const array TABLES = [
        'changesets', 'audit', 'events', 'idempotency_keys', 'receipts',
        'entries', 'variant_heads', 'revisions', 'revision_payloads', 'head_snapshots',
    ];

    public readonly FakeClock $clock;

    public readonly ActorId $actor;

    /** @var (Closure(): void)|null runs after an action's resolve() has read, before the pipeline goes on */
    public ?Closure $meanwhile = null;

    /** The issuer of the actor's credential: a service unless a test gives an agent, whose calls the envelope then says an agent issues. */
    public IssuerKind $credential = IssuerKind::Service;

    /** The call's classification access: internal unless a test gives another. */
    public ClassificationAccess $classification = ClassificationAccess::Internal;

    /** The authorizer: the fake, which allows, until granted() gives the actor roles. */
    private ?CommandAuthorizer $authorizer = null;

    private readonly FakeIdGenerator $ids;

    /**
     * @param  string  $now  the clock's time, NOW unless a test needs another, such as one that
     *                       shares the receipts with a process on the system clock
     * @param  CommandHooks|null  $hooks  the hooks the pipeline runs, such as the compiled registry's; none unless given
     */
    public function __construct(private readonly ?string $connection = null, int $seed = 1, string $now = self::NOW, private readonly ?CommandHooks $hooks = null)
    {
        $this->clock = new FakeClock(new DateTimeImmutable($now));
        $this->ids = new FakeIdGenerator(seed: $seed, clock: $this->clock);
        $identity = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $this->clock, new FakeIdGenerator(seed: 100 + $seed, clock: $this->clock));

        $this->actor = $identity->addActor(ActorClass::Staff)->id;
    }

    /**
     * The partitions for $now, NOW by default, and the structure: ROOT and HOME below it.
     */
    public static function seed(string $now = self::NOW): void
    {
        app(PartitionFixtures::class)->coverClock(new FakeClock(new DateTimeImmutable($now)), new DateInterval('P1D'));

        $superuser = StorageTables::superuser();
        $superuser->table('nodes')->insert(StorageTables::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert(StorageTables::node(self::HOME, self::ROOT, StorageTables::label(self::ROOT)));
    }

    public static function cleanUp(): void
    {
        DB::purge(StorageTables::SUPERUSER);
    }

    public static function type(string $name): TypeDefinition
    {
        return app(TypeCatalog::class)->named(new TypeName($name))
            ?? throw new LogicException(sprintf('The workbench has no type %s; run cms:generate.', $name));
    }

    public static function entry(string $id = self::ENTRY): EntryId
    {
        return EntryId::fromString($id);
    }

    public static function home(): NodeId
    {
        return NodeId::fromString(self::HOME);
    }

    public function create(TypeId $type, FieldValues $fields, string $key = 'create-1', ?EntryId $entry = null, ?NodeId $home = null): WriteResult
    {
        return $this->run(new CreateEntry($entry ?? self::entry(), $type, $home ?? self::home(), $fields), $key);
    }

    public function revise(int $version, FieldValues $fields, string $key = 'revise-1', ?EntryId $entry = null): WriteResult
    {
        return $this->run(new ReviseEntry($entry ?? self::entry(), new AggregateVersion($version), $fields), $key);
    }

    public function release(int $version, int $revision, string $key = 'release-1', ?EntryId $entry = null): WriteResult
    {
        return $this->run(new ReleaseVariant($entry ?? self::entry(), new RevisionNumber($revision), new AggregateVersion($version)), $key);
    }

    /**
     * entry.create version 2: the shared variant and a language's variant per locale.
     *
     * @param  array<string, FieldValues>  $locales  each language's fields by locale
     */
    public function createLocalized(TypeId $type, FieldValues $shared, array $locales, string $key = 'create-1', ?EntryId $entry = null, ?NodeId $home = null): WriteResult
    {
        $languages = [];

        foreach ($locales as $locale => $fields) {
            $languages[] = new LocaleFields(new Locale($locale), $fields);
        }

        return $this->run(new CreateLocalizedEntry($entry ?? self::entry(), $type, $home ?? self::home(), $shared, $languages), $key);
    }

    /**
     * entry.revise version 2: each variant at the version the caller saw with its fields.
     *
     * @param  array<string, array{int, FieldValues}>  $variants  by variant key: the version and the fields
     */
    public function reviseVariants(array $variants, string $key = 'revise-1', ?EntryId $entry = null): WriteResult
    {
        $revisions = [];

        foreach ($variants as $variant => [$version, $fields]) {
            $revisions[] = new VariantRevision(VariantKey::fromString($variant), new AggregateVersion($version), $fields);
        }

        return $this->run(new ReviseVariants($entry ?? self::entry(), $revisions), $key);
    }

    /**
     * variant.release version 2 of the variant.
     */
    public function releaseVariant(string $variant, int $version, int $revision, string $key = 'release-1', ?EntryId $entry = null): WriteResult
    {
        return $this->run(new ReleaseEntryVariant($entry ?? self::entry(), VariantKey::fromString($variant), new RevisionNumber($revision), new AggregateVersion($version)), $key);
    }

    /**
     * Gives the actor a role with the permissions, granted on ROOT in the locales, or in every
     * locale for null, written as the owner role, and has the world's calls authorized by the
     * PostgresCommandAuthorizer over the actor's grants from then on (PRD 5.10).
     *
     * @param  list<string>  $permissions
     * @param  list<string>|null  $locales
     */
    public function granted(string $role, array $permissions, ?array $locales = null): self
    {
        $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $this->clock, new FakeIdGenerator(seed: 900 + crc32($role) % 1000, clock: $this->clock));
        $fixtures->grant(
            $this->actor,
            $fixtures->role($role, ClassificationAccess::Sensitive, array_map(static fn (string $name): CommandName => new CommandName($name), $permissions)),
            NodeId::fromString(self::ROOT),
            GrantEffect::Allow,
            $locales === null ? null : array_map(static fn (string $locale): Locale => new Locale($locale), $locales),
        );
        $this->authorizer = new PostgresCommandAuthorizer(
            app(ConnectionResolverInterface::class),
            new PermissionRule,
            new EscalationGuard(new AdministrativePermissions(app(PermissionCatalog::class))),
            $this->connection,
        );

        return $this;
    }

    public function run(Command $command, string $key): WriteResult
    {
        $envelope = Envelope::external(
            $this->credential === IssuerKind::Agent ? IssuingSurface::Mcp : IssuingSurface::Rest,
            $this->credential === IssuerKind::Agent ? EnvelopeIssuer::Agent : EnvelopeIssuer::Human,
            $this->actor,
            new IdempotencyKey($key),
            new CorrelationId('entry-correlation'),
        );

        return $this->pipeline()->run(new CommandCall($command, $envelope, $this->access()));
    }

    public function pipeline(): CommandPipeline
    {
        $connections = app(ConnectionResolverInterface::class);
        $types = app(TypeCatalog::class);
        $reader = new PostgresEntryReader($connections, $this->connection);

        return new CommandPipeline(
            new FakeWriteActions([
                CreateEntry::class => $this->binding('entry.create', $this->interleaved(new CreateEntryAction($reader, $types))),
                ReviseEntry::class => $this->binding('entry.revise', $this->interleaved(new ReviseEntryAction($reader, $types))),
                ReleaseVariant::class => $this->binding('variant.release', $this->interleaved(new ReleaseVariantAction($reader, $types, new VariantReleasePlanner))),
                CreateLocalizedEntry::class => $this->binding('entry.create', $this->interleaved(new CreateLocalizedEntryAction($reader, $types)), 2),
                ReviseVariants::class => $this->binding('entry.revise', $this->interleaved(new ReviseVariantsAction($reader, $types)), 2),
                ReleaseEntryVariant::class => $this->binding('variant.release', $this->interleaved(new ReleaseEntryVariantAction($reader, $types, new VariantReleasePlanner)), 2),
            ]),
            new PostgresActorDirectory($connections, $this->connection),
            $this->authorizer ?? new FakeCommandAuthorizer,
            $types,
            app(FieldValidation::class),
            new PostgresRevisionContents($connections, $this->connection),
            new ReaderPublicPlacements(new PostgresPlacementReader($connections, $this->connection), $this->clock),
            new PostgresChangesetCommitter(
                $connections,
                $this->clock,
                $this->ids,
                new VersionLocks(
                    new PostgresActorVersionLock($connections, $this->connection),
                    new PostgresEntryVersionLock($connections, $this->connection),
                    new PostgresVariantVersionLock($connections, $this->connection),
                    new PostgresNodeVersionLock($connections, $this->connection),
                ),
                new MutationWriters(
                    new EntryCreatedWriter($connections, $this->connection),
                    new RevisionCreatedWriter($connections, $types, $this->connection),
                    new HeadMovedWriter($connections, $this->connection),
                    new VariantReleasedWriter($connections, $types, $this->connection),
                ),
                app(AffectedProjections::class),
                new PostgresReceiptStore($connections, $this->clock, $this->connection),
                $this->connection,
            ),
            new PostgresIdempotencyStore($connections, $this->clock, $this->connection),
            new PostgresReceiptStore($connections, $this->clock, $this->connection),
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(200)),
            new ConnectionCommandTransaction($connections, app(SavepointRefusal::class), $this->connection),
            new HookRunner($this->hooks ?? new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
            new AwaitWaitLevel(new PostgresReceiptStore($connections, $this->clock, $this->connection), new SystemPacing, new WaitSettings(0)),
        );
    }

    /**
     * The actor's context: its whole tree, internal classification access unless a test gives
     * another, through a service's credential with a sensitive ceiling unless a test gives an
     * agent's, whose ceiling is confidential.
     */
    public function access(): AccessContext
    {
        return new AccessContext(
            new ActorPrincipal($this->actor, [], $this->credential, $this->credential === IssuerKind::Agent ? $this->credential->maximumCeiling() : ClassificationAccess::Sensitive),
            [new AccessRegion(new NodePath(StorageTables::label(self::ROOT)))],
            $this->classification,
        );
    }

    /**
     * The number of rows in each table a create or a revise writes, read as the superuser, past
     * row level security.
     *
     * @return array<string, int>
     */
    public static function rows(): array
    {
        $superuser = StorageTables::superuser();
        $rows = [];

        foreach (self::TABLES as $table) {
            $rows[$table] = $superuser->table($table)->count();
        }

        return $rows;
    }

    /**
     * The action, run with what the test lets happen meanwhile when it set one.
     */
    private function interleaved(object $action): object
    {
        return $this->meanwhile instanceof Closure ? new InterleavedAction($action, $this->meanwhile) : $action;
    }

    private function binding(string $command, object $action, int $version = 1): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName($command), $version, $action);
    }
}
