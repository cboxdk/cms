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
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Placements\Actions\CreatePlacementAction;
use Cbox\Cms\Core\Placements\Actions\SetPlacementWindowAction;
use Cbox\Cms\Core\Placements\Domain\Commands\CreatePlacement;
use Cbox\Cms\Core\Placements\Domain\Commands\SetPlacementWindow;
use Cbox\Cms\Core\Placements\Domain\Dto\EntryRelease;
use Cbox\Cms\Core\Placements\Domain\Dto\LocaleSlug;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacementLocale;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredSite;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Entries\NoteType;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Placements\Fakes\FakePlacementReader;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use DateTimeImmutable;
use LogicException;

/**
 * The placement actions, placement.create and placement.set_window, in the command pipeline with
 * the fakes of its ports and of the contracts it reads (GUARDRAILS 9): an active editor, a
 * FakePlacementReader and a committer that records what it is asked to commit. Nothing touches a
 * database. The clock stands at NOW.
 *
 * The reader knows the site NORTH on the root NORTH_ROOT, publishing in da and en, with the section
 * NORTH_NODE and the mount MOUNT below the root; the site SOUTH on SOUTH_ROOT, publishing in da,
 * with SOUTH_NODE; FAR, a node the editor's regions do not reach; and the entry ENTRY of the note
 * type, active, with its shared head released at version RELEASE_VERSION, unless release() says
 * otherwise. Tests add placements with place().
 */
final class PlacementActionWorld
{
    public const string NOW = '2026-03-10T12:00:00.000000+00:00';

    public const string NORTH = '01936f5e-8a2b-7c3d-9e4f-000000000501';

    public const string NORTH_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000511';

    public const string NORTH_NODE = '01936f5e-8a2b-7c3d-9e4f-000000000512';

    public const string MOUNT = '01936f5e-8a2b-7c3d-9e4f-000000000513';

    public const string SOUTH = '01936f5e-8a2b-7c3d-9e4f-000000000502';

    public const string SOUTH_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000521';

    public const string SOUTH_NODE = '01936f5e-8a2b-7c3d-9e4f-000000000522';

    public const string FAR = '01936f5e-8a2b-7c3d-9e4f-000000000531';

    public const string ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000000541';

    public const string PLACEMENT = '01936f5e-8a2b-7c3d-9e4f-000000000551';

    /** The version of ENTRY's shared head. */
    public const int RELEASE_VERSION = 3;

    public readonly FakeIdentity $identity;

    public readonly ActorId $editor;

    public readonly FakePlacementReader $placements;

    public readonly FakeClock $clock;

    public FakeChangesetCommitter $committer;

    private int $keys = 0;

    public function __construct()
    {
        $this->identity = new FakeIdentity;
        $this->editor = $this->identity->addActor(ActorClass::Staff)->id;
        $this->clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $this->committer = new FakeChangesetCommitter;
        $this->placements = new FakePlacementReader()
            ->withNode($this->stored(self::NORTH_NODE, self::NORTH_ROOT))
            ->withNode($this->stored(self::SOUTH_NODE, self::SOUTH_ROOT))
            ->withNode(new StoredNode(NodeId::fromString(self::MOUNT), new AggregateVersion(1), $this->path(self::NORTH_ROOT, self::MOUNT), true))
            ->withNode($this->stored(self::FAR, self::FAR))
            ->unreached(NodeId::fromString(self::FAR))
            ->withSite(new StoredSite(SiteId::fromString(self::NORTH), new AggregateVersion(1), $this->path(self::NORTH_ROOT), [new Locale('da'), new Locale('en')]))
            ->withSite(new StoredSite(SiteId::fromString(self::SOUTH), new AggregateVersion(1), $this->path(self::SOUTH_ROOT), [new Locale('da')]))
            ->withEntry(self::entry(), new AggregateVersion(1));
        $this->release(EntryLifecycle::Active, ReleaseState::Released);
    }

    /**
     * ENTRY in the lifecycle state, with its shared head in the release state, or without one.
     */
    public function release(EntryLifecycle $lifecycle, ?ReleaseState $release): self
    {
        $this->placements->withRelease(new EntryRelease(
            self::entry(),
            NoteType::definition()->id,
            $lifecycle,
            $release,
            $release instanceof ReleaseState ? new AggregateVersion(self::RELEASE_VERSION) : null,
        ));

        return $this;
    }

    public static function entry(): EntryId
    {
        return EntryId::fromString(self::ENTRY);
    }

    public static function placement(string $id = self::PLACEMENT): PlacementId
    {
        return PlacementId::fromString($id);
    }

    public static function node(string $id): NodeId
    {
        return NodeId::fromString($id);
    }

    public static function site(string $id): SiteId
    {
        return SiteId::fromString($id);
    }

    /**
     * The window from the offset in hours from NOW, until the other offset, or open.
     */
    public static function window(int $fromHours, ?int $untilHours = null): TimeWindow
    {
        $now = new DateTimeImmutable(self::NOW);

        return new TimeWindow(
            $now->modify(sprintf('%+d hours', $fromHours)),
            $untilHours === null ? null : $now->modify(sprintf('%+d hours', $untilHours)),
        );
    }

    /**
     * A placement of ENTRY the reader knows, in da with the slug, the state and the window given.
     */
    public function place(string $id, string $node, string $slug, Visibility $visibility, ?TimeWindow $window, bool $canonical, int $version = 1, string $locale = 'da'): self
    {
        $this->placements->withPlacement(new StoredPlacement(self::placement($id), self::entry(), self::node($node), new AggregateVersion($version), [
            new StoredPlacementLocale(new Locale($locale), new Slug($slug), $visibility, $window, $canonical),
        ]));

        return $this;
    }

    public function commitWith(CommitOutcome $outcome): self
    {
        $this->committer = new FakeChangesetCommitter($outcome);

        return $this;
    }

    /**
     * The one changeset the committer was asked to commit.
     */
    public function committed(): PendingChangeset
    {
        return count($this->committer->pending) === 1
            ? $this->committer->pending[0]
            : throw new LogicException(sprintf('The committer was asked %d times, not once.', count($this->committer->pending)));
    }

    /**
     * placement.create of PLACEMENT, or the id given, for ENTRY below the node of the site.
     *
     * @param  array<string, string>  $slugs  by locale
     */
    public function create(string $node = self::NORTH_NODE, string $site = self::NORTH, array $slugs = ['da' => 'harbour'], string $placement = self::PLACEMENT, bool $agent = false): WriteResult
    {
        $localeSlugs = [];

        foreach ($slugs as $locale => $slug) {
            $localeSlugs[] = new LocaleSlug(new Locale($locale), new Slug($slug));
        }

        return $this->run(new CreatePlacement(self::placement($placement), self::entry(), self::node($node), self::site($site), $localeSlugs), $agent);
    }

    public function setWindow(string $placement, int $version, ?TimeWindow $window, string $locale = 'da', bool $agent = false): WriteResult
    {
        return $this->run(new SetPlacementWindow(self::placement($placement), new AggregateVersion($version), new Locale($locale), $window), $agent);
    }

    public function run(Command $command, bool $agent = false): WriteResult
    {
        $clock = new FakeClock;
        $keys = new FakeIdempotencyStore($clock)->session();
        $receipts = new FakeReceiptStore($clock)->session();
        $types = new FakeTypeCatalog(NoteType::definition());

        $pipeline = new CommandPipeline(
            new FakeWriteActions([
                CreatePlacement::class => $this->binding('placement.create', new CreatePlacementAction($this->placements, $this->clock)),
                SetPlacementWindow::class => $this->binding('placement.set_window', new SetPlacementWindowAction($this->placements, $types, $this->clock)),
            ]),
            $this->identity,
            new FakeCommandAuthorizer,
            $types,
            new FakeFieldValidation(new FakeTypeValidators(new NoteType)),
            new FakeRevisionContents,
            $this->committer,
            $keys,
            $receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(40)),
            new FakeCommandTransaction($keys, $receipts),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
            new AwaitWaitLevel($receipts, new FakePacing, new WaitSettings(0)),
        );

        $envelope = Envelope::external(
            IssuingSurface::Rest,
            $agent ? EnvelopeIssuer::Agent : EnvelopeIssuer::Human,
            $this->editor,
            new IdempotencyKey('placement-call-'.++$this->keys),
            new CorrelationId('placement-correlation'),
        );

        return $pipeline->run(new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($this->editor, [], $agent ? IssuerKind::Agent : IssuerKind::Service, ClassificationAccess::Confidential),
            [],
            ClassificationAccess::Internal,
        )));
    }

    private function stored(string $node, string $root): StoredNode
    {
        return new StoredNode(NodeId::fromString($node), new AggregateVersion(1), $node === $root ? $this->path($root) : $this->path($root, $node), false);
    }

    private function path(string $root, ?string $node = null): NodePath
    {
        $label = static fn (string $id): string => str_replace('-', '', $id);

        return new NodePath($node === null ? $label($root) : $label($root).'.'.$label($node));
    }

    /**
     * The binding of a command, version 1, as the registry gives it: it takes any write action.
     */
    private function binding(string $command, object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName($command), 1, $action);
    }
}
