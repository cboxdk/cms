<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Publishing;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
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
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Localization;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCapabilities;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredNode;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacementLocale;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Publishing\Actions\PublishEntryAction;
use Cbox\Cms\Core\Publishing\Actions\UnpublishEntryAction;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use Cbox\Cms\Core\Tests\Entries\Fakes\FakeEntryReader;
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
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use DateTimeImmutable;
use LogicException;

/**
 * The composite actions entry.publish and entry.unpublish in the command pipeline with the fakes of
 * its ports and of the contracts they read (GUARDRAILS 9): an active editor, the test type NoteType
 * with stages, and READING, a test type with stages none, in the catalog, a FakeEntryReader, a
 * FakePlacementReader, the revisions of a FakeRevisionContents, and a committer that records what
 * it is asked to commit. Nothing touches a database. The clock stands at NOW.
 *
 * The entry ENTRY is a note homed on HOME at version 2, its shared variant at version 6 on draft
 * revision 4, nothing released, and revision 4 is a valid note. The editor reaches HOME and AWAY,
 * and not FAR. Tests add placements with place(), such as HOME_PLACEMENT below HOME.
 */
final class PublishingActionWorld
{
    public const string NOW = '2026-03-10T12:00:00.000000+00:00';

    public const string ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000611';

    public const string HOME = '01936f5e-8a2b-7c3d-9e4f-000000000612';

    public const string AWAY = '01936f5e-8a2b-7c3d-9e4f-000000000613';

    public const string FAR = '01936f5e-8a2b-7c3d-9e4f-000000000614';

    public const string ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000000641';

    public const string OTHER_ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000000642';

    public const string HOME_PLACEMENT = '01936f5e-8a2b-7c3d-9e4f-000000000651';

    public const string AWAY_PLACEMENT = '01936f5e-8a2b-7c3d-9e4f-000000000652';

    public const string FAR_PLACEMENT = '01936f5e-8a2b-7c3d-9e4f-000000000653';

    /** The id of the test type with stages none. */
    public const string READING = '01936f5e-8a2b-7c3d-9e4f-0000000006d2';

    public readonly FakeIdentity $identity;

    public readonly ActorId $editor;

    public readonly FakeEntryReader $entries;

    public readonly FakePlacementReader $placements;

    public readonly FakeRevisionContents $revisions;

    public readonly FakeClock $clock;

    public FakeChangesetCommitter $committer;

    private int $keys = 0;

    public function __construct(?StoredHead $head = null, ?TypeId $type = null)
    {
        $this->identity = new FakeIdentity;
        $this->editor = $this->identity->addActor(ActorClass::Staff)->id;
        $this->clock = new FakeClock(new DateTimeImmutable(self::NOW));
        $this->committer = new FakeChangesetCommitter;
        $this->entries = new FakeEntryReader()
            ->withNode(self::node(self::HOME), new AggregateVersion(1))
            ->withEntry(self::entry(), $type ?? EntryActionWorld::type(), self::node(self::HOME), new AggregateVersion(2))
            ->withHead(self::entry(), VariantKey::shared(), $head ?? new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), null));
        $this->revisions = new FakeRevisionContents()
            ->with(self::entry(), VariantKey::shared(), new RevisionNumber(4), 3, EntryActionWorld::fields('Groceries'));
        $this->placements = new FakePlacementReader()
            ->withNode(new StoredNode(self::node(self::HOME), new AggregateVersion(1), $this->path(self::HOME), false))
            ->withNode(new StoredNode(self::node(self::AWAY), new AggregateVersion(1), $this->path(self::AWAY), false))
            ->withNode(new StoredNode(self::node(self::FAR), new AggregateVersion(1), $this->path(self::FAR), false))
            ->unreached(self::node(self::FAR))
            ->withEntry(self::entry(), new AggregateVersion(2));
    }

    /**
     * A world whose entry is of READING, a test type with stages none and no history, whose head
     * has no released revision.
     */
    public static function unstaged(): self
    {
        return new self(new StoredHead(new AggregateVersion(3), new RevisionNumber(1), new RevisionNumber(1), null), TypeId::fromString(self::READING));
    }

    public static function entry(string $id = self::ENTRY): EntryId
    {
        return EntryId::fromString($id);
    }

    public static function placement(string $id = self::HOME_PLACEMENT): PlacementId
    {
        return PlacementId::fromString($id);
    }

    public static function node(string $id): NodeId
    {
        return NodeId::fromString($id);
    }

    public static function at(int $hours): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW)->modify(sprintf('%+d hours', $hours));
    }

    /**
     * The window from the offset in hours from NOW, until the other offset, or open.
     */
    public static function window(int $fromHours, ?int $untilHours = null): TimeWindow
    {
        return new TimeWindow(self::at($fromHours), $untilHours === null ? null : self::at($untilHours));
    }

    /**
     * A placement below the node the reader knows, of ENTRY unless another entry is given, in each
     * locale given with the state, the window and whether it is canonical.
     *
     * @param  array<string, array{Visibility, ?TimeWindow, bool}>  $locales  by locale
     */
    public function place(string $id, string $node, array $locales, int $version = 1, string $entry = self::ENTRY): self
    {
        $stored = [];

        foreach ($locales as $locale => [$visibility, $window, $canonical]) {
            $stored[] = new StoredPlacementLocale(new Locale($locale), new Slug('harbour'), $visibility, $window, $canonical);
        }

        $this->placements->withPlacement(new StoredPlacement(self::placement($id), self::entry($entry), self::node($node), new AggregateVersion($version), $stored));

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

    public function publish(?int $revision = 4, ?TimeWindow $window = null, string $placement = self::HOME_PLACEMENT, int $placementVersion = 1, int $version = 6, string $locale = 'da', bool $agent = false, bool $dryRun = false): WriteResult
    {
        return $this->run(new PublishEntry(
            self::entry(),
            new AggregateVersion($version),
            $revision === null ? null : new RevisionNumber($revision),
            self::placement($placement),
            new AggregateVersion($placementVersion),
            new Locale($locale),
            $window,
        ), $agent, $dryRun);
    }

    public function unpublish(int $version = 6, bool $agent = false, bool $dryRun = false): WriteResult
    {
        return $this->run(new UnpublishEntry(self::entry(), new AggregateVersion($version)), $agent, $dryRun);
    }

    public function run(Command $command, bool $agent = false, bool $dryRun = false): WriteResult
    {
        $clock = new FakeClock;
        $keys = new FakeIdempotencyStore($clock)->session();
        $receipts = new FakeReceiptStore($clock)->session();
        $types = new FakeTypeCatalog(NoteType::definition(), new TypeDefinition(
            TypeId::fromString(self::READING),
            new TypeName('test:reading'),
            1,
            new TypeCapabilities(History::None, Stages::None, Localization::None, false),
            [],
            [],
        ));

        $pipeline = new CommandPipeline(
            new FakeWriteActions([
                PublishEntry::class => $this->binding('entry.publish', new PublishEntryAction($this->entries, $this->placements, $types, $this->clock)),
                UnpublishEntry::class => $this->binding('entry.unpublish', new UnpublishEntryAction($this->entries, $this->placements, $this->clock)),
            ]),
            $this->identity,
            new FakeCommandAuthorizer,
            $types,
            new FakeFieldValidation(new FakeTypeValidators(new NoteType)),
            $this->revisions,
            $this->committer,
            $keys,
            $receipts,
            new FakeCommandContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(40)),
            new FakeCommandTransaction($keys, $receipts),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
        );

        $envelope = Envelope::external(
            IssuingSurface::Rest,
            $agent ? EnvelopeIssuer::Agent : EnvelopeIssuer::Human,
            $this->editor,
            new IdempotencyKey('publishing-call-'.++$this->keys),
            new CorrelationId('publishing-correlation'),
            dryRun: $dryRun,
        );

        return $pipeline->run(new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($this->editor, [], IssuerKind::Service, ClassificationAccess::Confidential),
            [],
            ClassificationAccess::Internal,
        )));
    }

    private function path(string $node): NodePath
    {
        $label = static fn (string $id): string => str_replace('-', '', $id);

        return new NodePath($label(self::ROOT).'.'.$label($node));
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
