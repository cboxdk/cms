<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Core\Entries\Actions\CreateEntryAction;
use Cbox\Cms\Core\Entries\Adapter\EntryCreatedWriter;
use Cbox\Cms\Core\Entries\Adapter\HeadMovedWriter;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryReader;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryVersionLock;
use Cbox\Cms\Core\Entries\Adapter\PostgresRevisionContents;
use Cbox\Cms\Core\Entries\Adapter\PostgresVariantVersionLock;
use Cbox\Cms\Core\Entries\Adapter\RevisionCreatedWriter;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Adapter\ConnectionCommandTransaction;
use Cbox\Cms\Core\Pipeline\Adapter\PostgresChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Adapter\SavepointRefusal;
use Cbox\Cms\Core\Pipeline\Domain\AffectedProjections;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\Placements\Actions\CreatePlacementAction;
use Cbox\Cms\Core\Placements\Actions\SetPlacementWindowAction;
use Cbox\Cms\Core\Placements\Adapter\PlacementCanonicalSetWriter;
use Cbox\Cms\Core\Placements\Adapter\PlacementCreatedWriter;
use Cbox\Cms\Core\Placements\Adapter\PlacementLocaleAddedWriter;
use Cbox\Cms\Core\Placements\Adapter\PlacementWindowSetWriter;
use Cbox\Cms\Core\Placements\Adapter\PostgresCanonicalPlacementLock;
use Cbox\Cms\Core\Placements\Adapter\PostgresPlacementReader;
use Cbox\Cms\Core\Placements\Adapter\PostgresPlacementSlugLock;
use Cbox\Cms\Core\Placements\Adapter\PostgresPlacementVersionLock;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Core\Structure\Adapter\PostgresNodeVersionLock;
use Cbox\Cms\Core\Structure\Adapter\PostgresSiteVersionLock;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Entries\InterleavedAction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureSite;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Closure;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * The real command pipeline on Postgres for placement.create and placement.set_window, with
 * entry.create to make the entries they place (PRD 5.7, 6.2), on the default connection or the one
 * named: the command transaction, the Postgres stores, the actor directory, the entry and placement
 * readers, and the PostgresChangesetCommitter with the locks and writers of entries and
 * placements. Only what the kernel has no real implementation of yet is a fake: the authorizer,
 * which allows, the content hasher and the hooks.
 *
 * seed() writes the structure through the testkit's structure fixtures: the site NORTH, publishing
 * in da and en, with the section north below its root, and the site SOUTH, publishing in da, with
 * the section south. The clock stands at EntryWorld::NOW. Each world adds an active staff member as
 * its actor, whose regions are the roots given, both sites by default.
 */
final class PlacementWorld
{
    public readonly FakeClock $clock;

    public readonly ActorId $actor;

    /** @var (Closure(): void)|null runs after an action's resolve() has read, before the pipeline goes on */
    public ?Closure $meanwhile = null;

    private readonly FakeIdGenerator $ids;

    /**
     * @param  list<StructureNode>  $regions  the roots of the actor's access regions
     */
    public function __construct(
        private readonly array $regions,
        private readonly ?string $connection = null,
        int $seed = 1,
    ) {
        $this->clock = new FakeClock(new DateTimeImmutable(EntryWorld::NOW));
        $this->ids = new FakeIdGenerator(seed: $seed, clock: $this->clock);
        $identity = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $this->clock, new FakeIdGenerator(seed: 100 + $seed, clock: $this->clock));

        $this->actor = $identity->addActor(ActorClass::Staff)->id;
    }

    /**
     * The partitions for NOW and the structure of two sites.
     */
    public static function seed(): PlacementStructure
    {
        $clock = new FakeClock(new DateTimeImmutable(EntryWorld::NOW));
        app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));

        $fixtures = new PostgresStructureFixtures(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 900, clock: $clock));
        $north = $fixtures->site('north', [new Locale('da'), new Locale('en')]);
        $south = $fixtures->site('south', [new Locale('da')]);
        $northSection = $fixtures->node($north->root);
        $southSection = $fixtures->node($south->root);
        $fixtures->route($north, new Locale('da'), '/nyheder', $northSection);
        $fixtures->route($south, new Locale('da'), '/nyheder', $southSection);

        return new PlacementStructure($north, $northSection, $south, $southSection);
    }

    /**
     * entry.create of a fixture article homed on the node.
     */
    public function createEntry(EntryId $entry, StructureNode $home, string $key): WriteResult
    {
        $type = EntryWorld::type(EntryWorld::ARTICLE);

        return $this->run(new CreateEntry($entry, $type->id, $home->id, EntryFields::article()), $key);
    }

    /**
     * placement.create of the entry below the node of the site.
     *
     * @param  array<string, string>  $slugs  by locale
     */
    public function place(PlacementId $placement, EntryId $entry, StructureNode $node, StructureSite $site, array $slugs, string $key): WriteResult
    {
        $localeSlugs = [];

        foreach ($slugs as $locale => $slug) {
            $localeSlugs[] = new LocaleSlug(new Locale($locale), new Slug($slug));
        }

        return $this->run(new CreatePlacement($placement, $entry, $node->id, $site->id, $localeSlugs), $key);
    }

    public function setWindow(PlacementId $placement, int $version, ?TimeWindow $window, string $key, string $locale = 'da'): WriteResult
    {
        return $this->run(new SetPlacementWindow($placement, new AggregateVersion($version), new Locale($locale), $window), $key);
    }

    public function run(Command $command, string $key): WriteResult
    {
        $envelope = Envelope::external(
            IssuingSurface::Rest,
            EnvelopeIssuer::Human,
            $this->actor,
            new IdempotencyKey($key),
            new CorrelationId('placement-correlation'),
        );

        return $this->pipeline()->run(new CommandCall($command, $envelope, $this->access()));
    }

    public function access(): AccessContext
    {
        return new AccessContext(
            new ActorPrincipal($this->actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            array_map(static fn (StructureNode $root): AccessRegion => new AccessRegion($root->path), $this->regions),
            ClassificationAccess::Internal,
        );
    }

    private function pipeline(): CommandPipeline
    {
        $connections = app(ConnectionResolverInterface::class);
        $types = app(TypeCatalog::class);
        $placements = new PostgresPlacementReader($connections, $this->connection);

        return new CommandPipeline(
            new FakeWriteActions([
                CreateEntry::class => $this->binding('entry.create', new CreateEntryAction(new PostgresEntryReader($connections, $this->connection))),
                CreatePlacement::class => $this->binding('placement.create', $this->interleaved(new CreatePlacementAction($placements, $this->clock))),
                SetPlacementWindow::class => $this->binding('placement.set_window', $this->interleaved(new SetPlacementWindowAction($placements, $this->clock))),
            ]),
            new PostgresActorDirectory($connections, $this->connection),
            new FakeCommandAuthorizer,
            $types,
            app(FieldValidation::class),
            new PostgresRevisionContents($connections, $this->connection),
            new PostgresChangesetCommitter(
                $connections,
                $this->clock,
                $this->ids,
                new VersionLocks(
                    new PostgresActorVersionLock($connections, $this->connection),
                    new PostgresEntryVersionLock($connections, $this->connection),
                    new PostgresVariantVersionLock($connections, $this->connection),
                    new PostgresNodeVersionLock($connections, $this->connection),
                    new PostgresSiteVersionLock($connections, $this->connection),
                    new PostgresPlacementVersionLock($connections, $this->connection),
                    new PostgresPlacementSlugLock($connections, $this->connection),
                    new PostgresCanonicalPlacementLock($connections, $this->connection),
                ),
                new MutationWriters(
                    new EntryCreatedWriter($connections, $this->connection),
                    new RevisionCreatedWriter($connections, $types, $this->connection),
                    new HeadMovedWriter($connections, $this->connection),
                    new PlacementCreatedWriter($connections, $this->connection),
                    new PlacementLocaleAddedWriter($connections, $this->connection),
                    new PlacementWindowSetWriter($connections, $this->connection),
                    new PlacementCanonicalSetWriter($connections, $this->connection),
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
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
        );
    }

    /**
     * The action, run with what the test lets happen meanwhile when it set one.
     */
    private function interleaved(object $action): object
    {
        return $this->meanwhile instanceof Closure ? new InterleavedAction($action, $this->meanwhile) : $action;
    }

    private function binding(string $command, object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName($command), 1, $action);
    }
}
