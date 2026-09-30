<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\IssuerKind as CredentialIssuer;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Pipeline\RefusesCommand;
use Cbox\Cms\Contracts\Pipeline\ReportsVisibility;
use Cbox\Cms\Contracts\Plans\ChangesPublicVisibility;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Mutations\VariantReleased;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\DryRunReport;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\History;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\IdempotencyStore\Domain\Dto\IdempotencySettings;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\CommandContentHasher;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\HookRun;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\RevisionContent;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\InvalidCommandCall;
use Cbox\Cms\Core\Pipeline\Domain\MissingReplayReceipt;
use Cbox\Cms\Core\Pipeline\Domain\ReplayReceipt;
use Cbox\Cms\Core\Pipeline\Domain\RevisionContents;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;

/**
 * The command pipeline (GUARDRAILS 2.1, PRD 6.1, 6.2): the one way a write changes state. It owns
 * every phase, and the action only resolves and plans. The whole call runs in one
 * CommandTransaction, which sets the call's access context and commits only a call that
 * committed a changeset:
 *
 * 0. Idempotency (PRD 6.1). A call that is not a dry run claims its envelope's idempotency key in
 *    the IdempotencyStore, in the scope of its actor and command name, with the content hash of
 *    the command, waiting at most the kernel's wait budget (cbox-cms.idempotency.wait_budget_ms)
 *    for another call that holds the key. An internal issuer's key is the one its envelope derived
 *    from its unit of work. A fresh key runs the phases below, and the claim lasts until the
 *    transaction ends. A key completed with the same content is a replay: the call returns the
 *    first call's receipt from the ReceiptStore, built for the wait level this call asks for
 *    (ReplayReceipt), and runs nothing, so a retry after the first call committed never meets a
 *    version it changed. A key completed with other content is idempotency_conflict, and a key
 *    another call still holds after the wait budget is idempotency_in_flight, which a client
 *    retries. A dry run commits nothing and claims nothing.
 * 1. Resolve. The kernel reads the actor and each actor of its on-behalf-of chain through the
 *    ActorDirectory, as aggregates, and rejects the call with actor_not_active when one is missing
 *    or not active (invariant 37). Then the action's resolve() reads what the command touches. A
 *    command that ExpectsVersions is rejected with version_conflict when an aggregate is not at the
 *    version its caller saw (invariant 11), and so is a call whose two reads of one aggregate
 *    differ.
 * 2. Authorize, through the CommandAuthorizer with the call's AccessContext; a refusal is
 *    unauthorized. Then an action that RefusesCommand says whether what it read refuses the
 *    command, such as a slug another placement has, and the call is rejected with its errors.
 * 3. Plan: the action's plan() from the command and the aggregates. The kernel checks its shape
 *    at once: every aggregate a mutation changes was read, every revision's and release's type is
 *    a type of the TypeCatalog, and an entry's home node, when the action read it, exists, so the
 *    hooks only ever see a plan the kernel can read. A plan with a mutation that makes content
 *    public (ChangesPublicVisibility), such as a release or a window, is rejected with
 *    agent_visibility_forbidden when the envelope's issuer or the credential's issuer is an agent
 *    (invariant 18). A release of a type that has no revision to release, one with stages none or
 *    with a history that keeps no revisions, is type_not_releasable. Then the authorize
 *    hooks run on the pending plan; a denial is unauthorized. They run after plan() because they
 *    see the plan, and they can only add refusals to the CommandAuthorizer's decision.
 * 4. Transform: the transform hooks change fields of the plan's revisions (HookRunner).
 * 5. Validate. The kernel validates the plan as the transforms left it (invariant 12): the fields
 *    of every revision through the type's generated validator, so a transform can never produce
 *    fields that break a rule. A transform changes only fields, so the plan's shape stands. A
 *    released revision is read through RevisionContents and validated against its own schema
 *    version at the release stage, where the fields required on release are required (invariant
 *    5, 36); a revision the variant does not have, or one written under another schema version
 *    than the one whose rules this installation has, fails the validation. The
 *    validate hooks add their errors after the kernel's. A value for a field stored encrypted is
 *    refused with field_encryption_unavailable, because the key management that encrypts it comes
 *    with block B6 (PRD 12.2, 12.3). Any error rejects the call with validation_failed, followed by
 *    each error.
 * 6. Dry run: a call whose envelope asks for one ends here with the plan, its blast radius and its
 *    diff, and commits nothing. An action that ReportsVisibility adds every placement the plan
 *    makes visible, such as the placements a release shows (PRD 6.4).
 * 7. Commit, through the ChangesetCommitter, with every aggregate read and its version: the
 *    actors' and the action's. A stale read is version_conflict. A committed changeset completes
 *    the claim in the same transaction, so the key and the changeset commit together; a rejected
 *    call leaves the key fresh, and a retry runs again. A write that no partition covers, in the
 *    commit or in the completion of the key, is partition_missing: Postgres has failed the
 *    transaction, and the CommandTransaction rolls back everything the call wrote.
 *
 * resolve() and plan() get the command and the aggregates and nothing else: no connection, no
 * envelope and no access context, so an action cannot write or commit. The hooks get a view of
 * the plan filtered to the call's classification access, and a hook over its time budget rejects
 * the call with hook_budget_exceeded. The pipeline never begins or ends a transaction; the
 * CommandTransaction does.
 *
 * A plan with no mutation changes nothing, such as the deactivation of an actor that is
 * deactivated already: the call is rejected with validation_failed right after plan(), before the
 * hooks, and a dry run of it too, because there is nothing to commit.
 *
 * A rejected call and a dry run commit nothing, so their receipts carry no changeset; they are
 * never stored, and their retention class is Standard.
 */
#[Internal]
final readonly class CommandPipeline
{
    /** Where a command holds the fields of the revision it writes, for the paths of field errors. */
    public const string FIELDS = 'fields';

    /** Where a release's errors point: the revision it names, and its fields below it. */
    public const string REVISION = 'revision';

    public function __construct(
        private WriteActions $actions,
        private ActorDirectory $actors,
        private CommandAuthorizer $authorizer,
        private TypeCatalog $types,
        private FieldValidation $fields,
        private RevisionContents $revisions,
        private ChangesetCommitter $committer,
        private IdempotencyStore $keys,
        private ReceiptStore $receipts,
        private CommandContentHasher $hasher,
        private IdempotencySettings $idempotency,
        private CommandTransaction $transaction,
        private HookRunner $hooks,
    ) {}

    public function run(CommandCall $call): WriteResult
    {
        $binding = $this->actions->for($call->command);

        return $this->transaction->run($call->access, fn (): WriteResult => $this->claimed($call, $binding));
    }

    /**
     * Phase 0: the idempotency claim, and what it decides.
     */
    private function claimed(CommandCall $call, ActionBinding $binding): WriteResult
    {
        $envelope = $call->envelope;

        if ($envelope->dryRun) {
            return $this->phases($call, $binding, null);
        }

        $claim = $this->keys->claim(
            $envelope->idempotencyScope($binding->command),
            $envelope->idempotencyKey,
            $this->hasher->hash($binding->command, $binding->version, $call->command),
            $this->idempotency->waitBudget,
        );

        return match (true) {
            $claim instanceof Fresh => $this->phases($call, $binding, $claim),
            $claim instanceof Replay => $this->replay($call, $binding, $claim),
            $claim instanceof Conflict => $this->rejected($call, new CatalogError(
                ErrorCode::IdempotencyConflict,
                null,
                sprintf('The idempotency key "%s" was used before for this command with other content.', $envelope->idempotencyKey->value),
            )),
            default => $this->rejected($call, new CatalogError(
                ErrorCode::IdempotencyInFlight,
                null,
                sprintf('Another call with the idempotency key "%s" is still running after %d ms.', $envelope->idempotencyKey->value, $claim->waited->milliseconds),
            )),
        };
    }

    /**
     * The first call's receipt, for the wait level this call asks for.
     */
    private function replay(CommandCall $call, ActionBinding $binding, Replay $replay): WriteResult
    {
        $stored = $this->receipts->find($replay->changesetId);

        if (! $stored instanceof StoredReceipt) {
            throw MissingReplayReceipt::of($binding->command, $replay->changesetId);
        }

        return WriteResult::committed(ReplayReceipt::of($stored, $call->envelope->waitLevel));
    }

    /**
     * Phases 1 to 7, with the fresh claim a commit completes; a dry run has none.
     */
    private function phases(CommandCall $call, ActionBinding $binding, ?Fresh $claim): WriteResult
    {
        $envelope = $call->envelope;
        $action = $binding->action;

        $principals = [];

        foreach ($envelope->principals() as $index => $id) {
            $actor = $this->actors->find($id);

            if (! $actor instanceof Actor || ! $actor->isActive()) {
                return $this->rejected($call, new CatalogError(ErrorCode::ActorNotActive, null, $this->inactive($id, $actor, $index > 0)));
            }

            $principals[] = ReadVersion::at($id, new AggregateVersion($actor->version));
        }

        $aggregates = $action->resolve($call->command);
        $reads = [...$principals];
        $conflicts = [];

        foreach ($aggregates->versions()->reads as $read) {
            $kernel = new ReadVersions(...$principals)->of($read->aggregate);

            if (! $kernel instanceof ReadVersion) {
                $reads[] = $read;
            } elseif (! $this->sameVersion($kernel->version, $read->version)) {
                $conflicts[] = $this->conflict(new StaleRead($read->aggregate, $kernel->version, $read->version), 'was read twice in this call at different versions');
            }
        }

        $reads = new ReadVersions(...$reads);

        if ($call->command instanceof ExpectsVersions) {
            foreach ($call->command->expectedVersions()->reads as $expected) {
                $read = $reads->of($expected->aggregate);

                if (! $read instanceof ReadVersion) {
                    throw InvalidCommandCall::unexpectedExpectation($binding->command->value, $expected->aggregate);
                }

                if (! $this->sameVersion($expected->version, $read->version)) {
                    $conflicts[] = $this->conflict(new StaleRead($expected->aggregate, $expected->version, $read->version), 'is not at the version the caller saw');
                }
            }
        }

        if ($conflicts !== []) {
            return $this->rejected($call, ...$conflicts);
        }

        $authorization = $this->authorizer->authorize($call->access, $binding->command, $call->command, $aggregates);

        if (! $authorization->allowed()) {
            return $this->rejected($call, new CatalogError(ErrorCode::Unauthorized, null, (string) $authorization->reason));
        }

        if ($action instanceof RefusesCommand) {
            $refusals = $action->refusals($call->command, $aggregates);

            if ($refusals !== []) {
                return $this->rejected($call, ...$refusals);
            }
        }

        $plan = $action->plan($call->command, $aggregates);

        if ($plan->isEmpty()) {
            return $this->rejected($call, new CatalogError(ErrorCode::ValidationFailed, null, sprintf(
                'The command %s changes nothing here, so nothing was committed: its action planned no mutation for what it read.',
                $binding->command->value,
            )));
        }

        $errors = $this->shape($action::class, $plan, $reads);

        if ($errors !== []) {
            return $this->invalid($call, $errors);
        }

        $public = $this->agentIssued($call) ? $this->makesPublic($plan) : null;

        if ($public instanceof ChangesPublicVisibility) {
            return $this->rejected($call, new CatalogError(ErrorCode::AgentVisibilityForbidden, null, sprintf(
                'The command %s is issued by an agent, and its plan would make content public (%s on "%s"), which only a person may do (invariant 18).',
                $binding->command->value,
                $public::class,
                $public->aggregate()->aggregateKey(),
            )));
        }

        $unreleasable = $this->unreleasable($plan);

        if ($unreleasable instanceof CatalogError) {
            return $this->rejected($call, $unreleasable);
        }

        $hooks = $this->hooks->hooksOf($binding);
        $run = new HookRun($plan);

        foreach ([Phase::Authorize, Phase::Transform] as $phase) {
            $run = $this->hooks->run($phase, $hooks, $call, $binding, $run);

            if ($run->rejection instanceof CatalogError) {
                return $this->rejected($call, $run->rejection);
            }
        }

        $plan = $run->plan;
        $errors = $this->fieldErrors($plan);
        $run = $this->hooks->run(Phase::Validate, $hooks, $call, $binding, $run);

        if ($run->rejection instanceof CatalogError) {
            return $this->rejected($call, $run->rejection);
        }

        $errors = [...$errors, ...$run->errors];

        if ($errors !== []) {
            return $this->invalid($call, $errors);
        }

        if ($envelope->dryRun) {
            $visible = $action instanceof ReportsVisibility ? $action->becomesVisible($call->command, $aggregates, $plan) : [];

            return WriteResult::dryRun(Receipt::dryRun($envelope->waitLevel, RetentionClass::Standard), DryRunReport::of($plan, $reads, $visible));
        }

        try {
            $outcome = $this->committer->commit(new PendingChangeset($binding->command, $binding->version, $call->command, $envelope, $call->access, $plan, $reads));

            if ($outcome instanceof Committed && $claim instanceof Fresh) {
                $this->keys->complete($claim->token, $outcome->changesetId);
            }
        } catch (PartitionMissing $missing) {
            return $this->rejected($call, new CatalogError(ErrorCode::PartitionMissing, null, $missing->getMessage()));
        }

        if ($outcome instanceof Committed) {
            return WriteResult::committed($outcome->receipt);
        }

        if ($outcome instanceof VersionConflict) {
            return $this->rejected($call, ...array_map(
                fn (StaleRead $stale): CatalogError => $this->conflict($stale, 'changed after it was read'),
                $outcome->stale,
            ));
        }

        throw InvalidCommandCall::unknownOutcome($outcome::class);
    }

    /**
     * The kernel's rules for the shape of a plan: every aggregate a mutation changes was read,
     * every revision's type is a type of the installation, and an entry is not created on a home
     * node its action read as absent, which is how a node the actor's regions do not reach reads.
     *
     * @param  class-string  $action
     * @return list<CatalogError>
     */
    private function shape(string $action, Plan $plan, ReadVersions $reads): array
    {
        $errors = [];

        foreach ($plan->mutations() as $mutation) {
            if (! $reads->of($mutation->aggregate()) instanceof ReadVersion) {
                throw InvalidCommandCall::unreadAggregate($action, $mutation->aggregate());
            }

            if (($mutation instanceof RevisionCreated || $mutation instanceof VariantReleased) && ! $this->types->find($mutation->type) instanceof TypeDefinition) {
                $errors[] = new CatalogError(ErrorCode::ValidationFailed, null, sprintf('No type of this installation has the id %s.', $mutation->type->toString()));
            }

            if ($mutation instanceof EntryCreated) {
                $home = $reads->of($mutation->home);

                if ($home instanceof ReadVersion && ! $home->existed()) {
                    $errors[] = new CatalogError(ErrorCode::ValidationFailed, null, sprintf('No node %s exists that the actor can reach, so it cannot be the home of the entry %s.', $mutation->home->toString(), $mutation->entry->toString()));
                }
            }
        }

        return $errors;
    }

    /**
     * Whether an agent issues the call (invariant 18): the envelope says the issuer is an agent, or
     * the principal's credential was issued for an agent.
     */
    private function agentIssued(CommandCall $call): bool
    {
        $principal = $call->access->principal;

        return $call->envelope->issuerKind === EnvelopeIssuer::Agent
            || ($principal instanceof ActorPrincipal && $principal->issuerKind === CredentialIssuer::Agent);
    }

    /**
     * The plan's first mutation that makes content public, or null when it makes nothing public.
     */
    private function makesPublic(Plan $plan): ?ChangesPublicVisibility
    {
        foreach ($plan->mutations() as $mutation) {
            if ($mutation instanceof ChangesPublicVisibility && $mutation->makesPublic()) {
                return $mutation;
            }
        }

        return null;
    }

    /**
     * The first release of a type whose entries have no revision to release (PRD 4.1, 5.6): a type
     * with stages none is public as soon as it is saved, and a type whose history is audit-only or
     * none keeps no revision for the head to point at.
     */
    private function unreleasable(Plan $plan): ?CatalogError
    {
        foreach ($plan->mutations() as $mutation) {
            $type = $mutation instanceof VariantReleased ? $this->types->find($mutation->type) : null;

            if (! $type instanceof TypeDefinition) {
                continue;
            }

            $capabilities = $type->capabilities;

            if ($capabilities->stages === Stages::None || $capabilities->history !== History::Full) {
                return new CatalogError(ErrorCode::TypeNotReleasable, null, sprintf(
                    'The type %s has stages %s and history %s, so no revision of its entries is released: %s',
                    $type->name->value,
                    $capabilities->stages->value,
                    $capabilities->history->value,
                    $capabilities->stages === Stages::None
                        ? 'an entry of it is public as soon as it is saved.'
                        : 'it keeps no revision for the head of a variant to point at.',
                ));
            }
        }

        return null;
    }

    /**
     * The fields of every revision through its type's generated validator, and the fields of every
     * released revision at the release stage (phase 5).
     *
     * @return list<CatalogError>
     */
    private function fieldErrors(Plan $plan): array
    {
        $errors = [];

        foreach ($plan->mutations() as $mutation) {
            $type = $mutation instanceof RevisionCreated || $mutation instanceof VariantReleased ? $this->types->find($mutation->type) : null;

            if ($mutation instanceof RevisionCreated && $type instanceof TypeDefinition) {
                array_push($errors, ...$this->fields->validate($type, $mutation->fields, ValidationStage::Write, new FieldPath(self::FIELDS))->errors);
                array_push($errors, ...$this->encrypted($type, $mutation));
            }

            if ($mutation instanceof VariantReleased && $type instanceof TypeDefinition) {
                array_push($errors, ...$this->releaseErrors($type, $mutation));
            }
        }

        return $errors;
    }

    /**
     * The released revision against its own schema version, at the release stage (invariant 5).
     *
     * @return list<CatalogError>
     */
    private function releaseErrors(TypeDefinition $type, VariantReleased $release): array
    {
        $content = $this->revisions->find($release->entry, $release->variant, $release->revision, $type);
        $at = new FieldPath(self::REVISION);

        if (! $content instanceof RevisionContent) {
            return [new CatalogError(ErrorCode::ValidationFailed, $at, sprintf(
                'The variant "%s" has no revision %d that the actor can reach, so there is nothing to release.',
                $release->aggregate()->aggregateKey(),
                $release->revision->value,
            ))];
        }

        if (! $content->fields instanceof FieldValues) {
            return [new CatalogError(ErrorCode::ValidationFailed, $at, sprintf(
                'Revision %d of the variant "%s" was written under schema version %d of %s, and this installation has the rules of version %d only, so it cannot validate the revision against its own version (invariant 5). Save it again under version %d and release that revision.',
                $release->revision->value,
                $release->aggregate()->aggregateKey(),
                $content->schemaVersion,
                $type->name->value,
                $type->version,
                $type->version,
            ))];
        }

        return $this->fields->validate($type, $content->fields, ValidationStage::Release, $at)->errors;
    }

    /**
     * A value for a field stored as ciphertext (PRD 12.2): the key management that encrypts it with
     * its scope or subject key comes with block B6 (PRD 12.3), so until then the kernel refuses the
     * value rather than store it in plain text, and an encrypted field can only be left empty.
     *
     * @return list<CatalogError>
     */
    private function encrypted(TypeDefinition $type, RevisionCreated $mutation): array
    {
        $errors = [];

        foreach ($type->fields as $field) {
            $map = $field->namespace instanceof FieldNamespace ? $mutation->fields->extension($field->namespace) : $mutation->fields->own;
            $value = $map instanceof FieldMap ? $map->get($field->handle) : null;

            if ($field->encrypted && $value instanceof FieldValue && ! $value instanceof NullValue) {
                $errors[] = new CatalogError(
                    ErrorCode::FieldEncryptionUnavailable,
                    new FieldPath(self::FIELDS, ...explode('.', $field->address())),
                    sprintf('The field "%s" is classified %s and is stored encrypted, and this installation has no key to encrypt it with yet, so it takes no value.', $field->address(), $field->classification->value),
                );
            }
        }

        return $errors;
    }

    /**
     * @param  non-empty-list<CatalogError>  $errors
     */
    private function invalid(CommandCall $call, array $errors): WriteResult
    {
        return $this->rejected($call, new CatalogError(ErrorCode::ValidationFailed, null, sprintf(
            'The plan breaks %d rule%s of its types; the errors below say which fields to correct.',
            count($errors),
            count($errors) === 1 ? '' : 's',
        )), ...$errors);
    }

    private function rejected(CommandCall $call, CatalogError $error, CatalogError ...$more): WriteResult
    {
        return WriteResult::rejected(Receipt::rejected($call->envelope->waitLevel, RetentionClass::Standard), $error, ...$more);
    }

    private function conflict(StaleRead $stale, string $what): CatalogError
    {
        return new CatalogError(ErrorCode::VersionConflict, null, sprintf(
            'The aggregate "%s" %s: expected %s, found %s.',
            $stale->aggregate->aggregateKey(),
            $what,
            $this->version($stale->read),
            $this->version($stale->current),
        ));
    }

    private function inactive(ActorId $id, ?Actor $actor, bool $onBehalfOf): string
    {
        $who = $onBehalfOf ? 'The actor the call acts on behalf of' : 'The actor';

        return $actor instanceof Actor
            ? sprintf('%s, %s, is %s.', $who, $id->toString(), $actor->state->value)
            : sprintf('%s, %s, does not exist.', $who, $id->toString());
    }

    private function sameVersion(?AggregateVersion $one, ?AggregateVersion $other): bool
    {
        return $one instanceof AggregateVersion && $other instanceof AggregateVersion
            ? $one->equals($other)
            : $one === $other;
    }

    private function version(?AggregateVersion $version): string
    {
        return $version instanceof AggregateVersion ? 'version '.$version->value : 'no aggregate';
    }
}
