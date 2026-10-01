<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\AccessRegion;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
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
use Cbox\Cms\Core\Seeding\Actions\SeedDataset;
use Cbox\Cms\Core\Seeding\Actions\SeedEntriesAction;
use Cbox\Cms\Core\Seeding\Boundary\SeedContentHasher;
use Cbox\Cms\Core\Seeding\Domain\Commands\SeedEntries;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeededEntry;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedSettings;
use Cbox\Cms\Core\Seeding\Domain\SeedAuthorizer;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Access\Fakes\FakeAccessContexts;
use Cbox\Cms\Core\Tests\Operations\Fakes\FakeOperationRunner;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeRevisionContents;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Core\Tests\Seeding\Fakes\FakeSeedReader;
use Cbox\Cms\Core\Tests\Seeding\Fakes\FakeSeedTargets;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencySession;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptSession;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use LogicException;

/**
 * seed.entries through the real command pipeline with fakes (GUARDRAILS 9): the seeder's own
 * authorizer and content hasher, the fake reader of the seeder, the fake committer and stores, and
 * the test types LockedType and DraftNoteType. The seeding actor is a service actor whose region is
 * the node HOME, at version 1, with internal classification access. The idempotency and receipt
 * stores live as long as the world, so a unit of work run twice replays.
 */
final class SeedActionWorld
{
    public const string HOME = '01936f5e-8a2b-7c3d-9e4f-0000000047a1';

    public const string NOWHERE = '01936f5e-8a2b-7c3d-9e4f-0000000047a9';

    /** The root above HOME and SECOND, where the actor's region starts. */
    public const string ROOT = '01936f5e-8a2b-7c3d-9e4f-0000000047a0';

    public const string SECOND = '01936f5e-8a2b-7c3d-9e4f-0000000047a2';

    public readonly FakeIdentity $identity;

    public readonly ActorId $actor;

    public readonly FakeSeedReader $reader;

    public readonly FakeRevisionContents $revisions;

    public FakeChangesetCommitter $committer;

    public ClassificationAccess $access = ClassificationAccess::Internal;

    public ?FakeSeedTargets $targets = null;

    /** @var list<SeededEntry> the entries that exist before the world's commands run */
    private array $existing = [];

    private readonly FakeIdGenerator $ids;

    private readonly FakeIdempotencySession $keys;

    private readonly FakeReceiptSession $receipts;

    public function __construct()
    {
        $clock = new FakeClock;
        $this->identity = new FakeIdentity;
        $this->actor = $this->identity->addActor(ActorClass::Service)->id;
        $this->reader = new FakeSeedReader()->withNode(self::home(), new AggregateVersion(1));
        $this->revisions = new FakeRevisionContents;
        $this->keys = new FakeIdempotencyStore($clock)->session();
        $this->receipts = new FakeReceiptStore($clock)->session();
        $this->ids = new FakeIdGenerator(clock: $clock);
        $this->committer = new FakeChangesetCommitter(receipts: $this->receipts, ids: $this->ids);
    }

    public static function home(): NodeId
    {
        return NodeId::fromString(self::HOME);
    }

    /**
     * An entry of the test type with the fields given as text.
     *
     * @param  array<string, string>  $fields
     */
    public static function entry(int $number, string $type, array $fields, bool $release = false, ?NodeId $home = null): SeededEntry
    {
        $named = [];

        foreach ($fields as $handle => $value) {
            $named[] = new NamedValue(new FieldHandle($handle), new TextValue($value));
        }

        return new SeededEntry(
            EntryId::fromString(sprintf('01936f5e-8a2b-7c3d-9e4f-%012x', 0x47E0 + $number)),
            TypeId::fromString($type),
            $home ?? self::home(),
            new FieldValues(new FieldMap(...$named)),
            $release,
        );
    }

    /**
     * Entries that exist already, known to the reader of seed.entries and to the targets of the
     * datasets the world builds after this call, each homed on HOME or SECOND.
     */
    public function exists(SeededEntry ...$entries): self
    {
        foreach ($entries as $entry) {
            $this->reader->withEntry($entry->entry);
            $this->existing[] = $entry;
        }

        return $this;
    }

    public function commitWith(CommitOutcome $outcome): self
    {
        $this->committer = new FakeChangesetCommitter($outcome, $this->receipts, $this->ids);

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

    public function run(SeedEntries $command, string $unit = 'seed:test@1:1:0:1'): WriteResult
    {
        $envelope = Envelope::internal(IssuingSurface::Seed, EnvelopeIssuer::Seed, $this->actor, new UnitOfWork($unit), new CorrelationId('seed-test'));

        return $this->pipeline()->run(new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($this->actor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [new AccessRegion(new NodePath(str_replace('-', '', self::HOME)))],
            $this->access,
        )));
    }

    /**
     * The pipeline as CoreServiceProvider builds the seeder's, with the world's fakes.
     */
    public function pipeline(): CommandPipeline
    {
        $types = self::types();

        return new CommandPipeline(
            new FakeWriteActions([SeedEntries::class => $this->binding(new SeedEntriesAction($this->reader))]),
            $this->identity,
            new SeedAuthorizer($types),
            $types,
            new FakeFieldValidation(self::validators()),
            $this->revisions,
            $this->committer,
            $this->keys,
            $this->receipts,
            new SeedContentHasher,
            new IdempotencySettings(WaitBudget::milliseconds(40)),
            new FakeCommandTransaction($this->keys, $this->receipts),
            new HookRunner(new FakeCommandHooks, new HookPlans($types), new FakeStopwatch, new FakeHookOverruns),
            new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
            new AwaitWaitLevel($this->receipts, new FakePacing, new WaitSettings(0)),
        );
    }

    /**
     * SeedDataset with the world's pipeline, as the world's actor unless another is given, granted
     * internal access on ROOT, where HOME and SECOND lie, both known to the reader unless $nodes
     * is false, when the actor reaches no node. Its targets, kept in $targets, know the entries
     * that exist().
     */
    public function dataset(?ActorId $actor = null, ?FakeTypeCatalog $types = null, ?FakeOperationRunner $runner = null, bool $nodes = true): SeedDataset
    {
        $root = str_replace('-', '', self::ROOT);
        $second = NodeId::fromString(self::SECOND);
        $targets = new FakeSeedTargets;

        if ($nodes) {
            $targets->withNode(self::home(), new NodePath($root.'.'.str_replace('-', '', self::HOME)))
                ->withNode($second, new NodePath($root.'.'.str_replace('-', '', self::SECOND)));
            $this->reader->withNode($second, new AggregateVersion(4));
        }

        foreach ($this->existing as $entry) {
            $targets->withEntry($entry->entry, new NodePath($root.'.'.str_replace('-', '', $entry->home->toString())));
        }

        $this->targets = $targets;

        return new SeedDataset(
            new SeedSettings($actor ?? $this->actor),
            $this->identity,
            new FakeAccessContexts()->grant($this->actor, ClassificationAccess::Internal, new AccessRegion(new NodePath($root))),
            $targets,
            $types ?? self::types(),
            self::validators(),
            $runner ?? new FakeOperationRunner,
            $this->pipeline(),
        );
    }

    /**
     * The binding of seed.entries, version 1, as the registry gives it: it takes any write action.
     */
    private function binding(object $action): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName('seed.entries'), 1, $action);
    }

    public static function types(): FakeTypeCatalog
    {
        return new FakeTypeCatalog(LockedType::definition(), DraftNoteType::definition());
    }

    public static function validators(): FakeTypeValidators
    {
        return new FakeTypeValidators(new LockedType, new DraftNoteType);
    }
}
