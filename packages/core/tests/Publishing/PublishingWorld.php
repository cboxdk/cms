<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Publishing;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
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
use Cbox\Cms\Core\Entries\Adapter\VariantReleasedWriter;
use Cbox\Cms\Core\Entries\Adapter\VariantUnreleasedWriter;
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
use Cbox\Cms\Core\Placements\Adapter\PlacementClosedWriter;
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
use Cbox\Cms\Core\Publishing\Actions\PublishEntryAction;
use Cbox\Cms\Core\Publishing\Actions\UnpublishEntryAction;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Core\Structure\Adapter\PostgresNodeVersionLock;
use Cbox\Cms\Core\Structure\Adapter\PostgresSiteVersionLock;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Entries\EntryWorld;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureNode;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Domain\Dto\StructureSite;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * The real command pipeline on Postgres for entry.publish and entry.unpublish (PRD 6.2, 6.4), with
 * entry.create, placement.create and placement.set_window to make what they publish: the command
 * transaction, the Postgres stores, the actor directory, the entry and placement readers, the
 * revision contents a release is validated against, and the PostgresChangesetCommitter with the
 * locks and writers of entries, releases and placements. Only what the kernel has no real
 * implementation of yet is a fake: the authorizer, which allows, the content hasher and the hooks.
 *
 * The structure is PlacementWorld::seed()'s: the sites north and south, each with a section. The
 * clock stands at EntryWorld::NOW. Each world adds an active staff member as its actor, whose
 * regions are the roots given.
 */
final readonly class PublishingWorld
{
    public FakeClock $clock;

    public ActorId $actor;

    private FakeIdGenerator $ids;

    /**
     * @param  list<StructureNode>  $regions  the roots of the actor's access regions
     */
    public function __construct(
        private array $regions,
        int $seed = 1,
    ) {
        $this->clock = new FakeClock(new DateTimeImmutable(EntryWorld::NOW));
        $this->ids = new FakeIdGenerator(seed: $seed, clock: $this->clock);
        $identity = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $this->clock, new FakeIdGenerator(seed: 100 + $seed, clock: $this->clock));

        $this->actor = $identity->addActor(ActorClass::Staff)->id;
    }

    /**
     * entry.create of an entry of the workbench's type, homed on the node.
     */
    public function createEntry(EntryId $entry, string $type, FieldValues $fields, StructureNode $home, string $key): WriteResult
    {
        return $this->run(new CreateEntry($entry, EntryWorld::type($type)->id, $home->id, $fields), $key);
    }

    /**
     * placement.create of the entry below the node of the site, in da with the slug.
     */
    public function place(PlacementId $placement, EntryId $entry, StructureNode $node, StructureSite $site, string $slug, string $key): WriteResult
    {
        return $this->run(new CreatePlacement($placement, $entry, $node->id, $site->id, [new LocaleSlug(new Locale('da'), new Slug($slug))]), $key);
    }

    public function setWindow(PlacementId $placement, int $version, ?TimeWindow $window, string $key): WriteResult
    {
        return $this->run(new SetPlacementWindow($placement, new AggregateVersion($version), new Locale('da'), $window), $key);
    }

    public function publish(EntryId $entry, int $version, ?int $revision, PlacementId $placement, int $placementVersion, string $key, ?TimeWindow $window = null, bool $dryRun = false): WriteResult
    {
        return $this->run(new PublishEntry(
            $entry,
            new AggregateVersion($version),
            $revision === null ? null : new RevisionNumber($revision),
            $placement,
            new AggregateVersion($placementVersion),
            new Locale('da'),
            $window,
        ), $key, $dryRun);
    }

    public function unpublish(EntryId $entry, int $version, string $key): WriteResult
    {
        return $this->run(new UnpublishEntry($entry, new AggregateVersion($version)), $key);
    }

    public function run(Command $command, string $key, bool $dryRun = false): WriteResult
    {
        $envelope = Envelope::external(
            IssuingSurface::Rest,
            EnvelopeIssuer::Human,
            $this->actor,
            new IdempotencyKey($key),
            new CorrelationId('publishing-correlation'),
            dryRun: $dryRun,
        );

        return $this->pipeline()->run(new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($this->actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            array_map(static fn (StructureNode $root): AccessRegion => new AccessRegion($root->path), $this->regions),
            ClassificationAccess::Internal,
        )));
    }

    private function pipeline(): CommandPipeline
    {
        $connections = app(ConnectionResolverInterface::class);
        $types = app(TypeCatalog::class);
        $entries = new PostgresEntryReader($connections);
        $placements = new PostgresPlacementReader($connections);

        return new CommandPipeline(
            new FakeWriteActions([
                CreateEntry::class => $this->binding('entry.create', new CreateEntryAction($entries)),
                CreatePlacement::class => $this->binding('placement.create', new CreatePlacementAction($placements, $this->clock)),
                SetPlacementWindow::class => $this->binding('placement.set_window', new SetPlacementWindowAction($placements, $this->clock)),
                PublishEntry::class => $this->binding('entry.publish', new PublishEntryAction($entries, $placements, $types, $this->clock)),
                UnpublishEntry::class => $this->binding('entry.unpublish', new UnpublishEntryAction($entries, $placements, $this->clock)),
            ]),
            new PostgresActorDirectory($connections),
            new FakeCommandAuthorizer,
            $types,
            app(FieldValidation::class),
            new PostgresRevisionContents($connections),
            new PostgresChangesetCommitter(
                $connections,
                $this->clock,
                $this->ids,
                new VersionLocks(
                    new PostgresActorVersionLock($connections),
                    new PostgresEntryVersionLock($connections),
                    new PostgresVariantVersionLock($connections),
                    new PostgresNodeVersionLock($connections),
                    new PostgresSiteVersionLock($connections),
                    new PostgresPlacementVersionLock($connections),
                    new PostgresPlacementSlugLock($connections),
                    new PostgresCanonicalPlacementLock($connections),
                ),
                new MutationWriters(
                    new EntryCreatedWriter($connections),
                    new RevisionCreatedWriter($connections, $types),
                    new HeadMovedWriter($connections),
                    new VariantReleasedWriter($connections, $types),
                    new VariantUnreleasedWriter($connections, $types),
                    new PlacementCreatedWriter($connections),
                    new PlacementLocaleAddedWriter($connections),
                    new PlacementWindowSetWriter($connections),
                    new PlacementCanonicalSetWriter($connections),
                    new PlacementClosedWriter($connections),
                ),
                app(AffectedProjections::class),
                new PostgresReceiptStore($connections, $this->clock),
            ),
            new PostgresIdempotencyStore($connections, $this->clock),
            new PostgresReceiptStore($connections, $this->clock),
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(200)),
            new ConnectionCommandTransaction($connections, app(SavepointRefusal::class)),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
        );
    }

    private function binding(string $command, object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName($command), 1, $action);
    }
}
