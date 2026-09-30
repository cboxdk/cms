<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Entries\Actions\CreateEntryAction;
use Cbox\Cms\Core\Entries\Actions\ReviseEntryAction;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Actions\HookRunner;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\HookPlans;
use Cbox\Cms\Core\Tests\Entries\Fakes\FakeEntryReader;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeChangesetCommitter;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandAuthorizer;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandContentHasher;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeFieldValidation;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeHookOverruns;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeWriteActions;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Validation\FakeTypeValidators;
use LogicException;

/**
 * The entry actions, entry.create and entry.revise, in the command pipeline with the fakes of its
 * ports and of the contracts it reads (GUARDRAILS 9): an active editor, the test type NoteType in
 * the catalog and its validator, the entries and nodes of a FakeEntryReader, which knows the node
 * HOME at version 1, and a committer that records what it is asked to commit. Nothing touches a
 * database.
 */
final class EntryActionWorld
{
    public const string ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000003e1';

    public const string HOME = '01936f5e-8a2b-7c3d-9e4f-0000000003a1';

    public readonly FakeIdentity $identity;

    public readonly ActorId $editor;

    public readonly FakeEntryReader $entries;

    public FakeChangesetCommitter $committer;

    private int $keys = 0;

    public function __construct()
    {
        $this->identity = new FakeIdentity;
        $this->editor = $this->identity->addActor(ActorClass::Staff)->id;
        $this->entries = new FakeEntryReader()->withNode(self::home(), new AggregateVersion(1));
        $this->committer = new FakeChangesetCommitter;
    }

    public static function entry(): EntryId
    {
        return EntryId::fromString(self::ENTRY);
    }

    public static function home(): NodeId
    {
        return NodeId::fromString(self::HOME);
    }

    public static function type(): TypeId
    {
        return TypeId::fromString(NoteType::ID);
    }

    /**
     * The fields of a note: its title, and more fields when given.
     *
     * @param  array<string, FieldValue>  $more
     */
    public static function fields(string $title, array $more = []): FieldValues
    {
        $named = [new NamedValue(new FieldHandle('title'), new TextValue($title))];

        foreach ($more as $handle => $value) {
            $named[] = new NamedValue(new FieldHandle($handle), $value);
        }

        return new FieldValues(new FieldMap(...$named));
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

    public function create(FieldValues $fields, ?TypeId $type = null, ?NodeId $home = null): WriteResult
    {
        return $this->run(new CreateEntry(self::entry(), $type ?? self::type(), $home ?? self::home(), $fields));
    }

    public function revise(int $version, FieldValues $fields): WriteResult
    {
        return $this->run(new ReviseEntry(self::entry(), new AggregateVersion($version), $fields));
    }

    public function run(Command $command): WriteResult
    {
        $clock = new FakeClock;
        $keys = new FakeIdempotencyStore($clock)->session();
        $receipts = new FakeReceiptStore($clock)->session();
        $types = new FakeTypeCatalog(NoteType::definition());

        $pipeline = new CommandPipeline(
            new FakeWriteActions([
                CreateEntry::class => $this->binding('entry.create', new CreateEntryAction($this->entries)),
                ReviseEntry::class => $this->binding('entry.revise', new ReviseEntryAction($this->entries)),
            ]),
            $this->identity,
            new FakeCommandAuthorizer,
            $types,
            new FakeFieldValidation(new FakeTypeValidators(new NoteType)),
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
            EnvelopeIssuer::Human,
            $this->editor,
            new IdempotencyKey('entry-call-'.++$this->keys),
            new CorrelationId('entry-correlation'),
        );

        return $pipeline->run(new CommandCall($command, $envelope, new AccessContext(
            new ActorPrincipal($this->editor, [], IssuerKind::Service, ClassificationAccess::Sensitive),
            [],
            ClassificationAccess::Internal,
        )));
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
