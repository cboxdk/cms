<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Identity\Actor;
use Cbox\Cms\Contracts\Identity\ActorDirectory;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\DryRunReport;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommandAuthorizer;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\IdempotencyConflict;
use Cbox\Cms\Core\Pipeline\Domain\Dto\IdempotencyInFlight;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Pipeline\Domain\InvalidCommandCall;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;

/**
 * The command pipeline (GUARDRAILS 2.1, PRD 6.1, 6.2): the one way a write changes state. It owns
 * every phase, and the action only resolves and plans:
 *
 * 1. Resolve. The kernel reads the actor and each actor of its on-behalf-of chain through the
 *    ActorDirectory, as aggregates, and rejects the call with actor_not_active when one is missing
 *    or not active (invariant 37). Then the action's resolve() reads what the command touches. A
 *    command that ExpectsVersions is rejected with version_conflict when an aggregate is not at the
 *    version its caller saw (invariant 11), and so is a call whose two reads of one aggregate
 *    differ.
 * 2. Authorize, through the CommandAuthorizer with the call's AccessContext; a refusal is
 *    unauthorized.
 * 3. Plan: the action's plan() from the command and the aggregates.
 * 5. Validate. The kernel's rules: every aggregate a mutation changes was read, and every
 *    revision's type is a type of the TypeCatalog; then the fields of every revision through the
 *    type's generated validator. Any error rejects the call with validation_failed, followed by
 *    each field error.
 * 6. Dry run: a call whose envelope asks for one ends here with the plan, its blast radius and its
 *    diff, and commits nothing.
 * 7. Commit, through the ChangesetCommitter, with every aggregate read and its version: the
 *    actors' and the action's. A stale read is version_conflict, and the idempotency outcomes are
 *    idempotency_conflict and idempotency_in_flight.
 *
 * resolve() and plan() get the command and the aggregates and nothing else: no connection, no
 * envelope and no access context, so an action cannot write or commit. Phase 4, the transform
 * hooks, comes with the hook extension point. The pipeline never begins or ends a transaction; the
 * commit does.
 *
 * A rejected call and a dry run commit nothing, so their receipts carry no changeset; they are
 * never stored, and their retention class is Standard.
 */
#[Internal]
final readonly class CommandPipeline
{
    /** Where a command holds the fields of the revision it writes, for the paths of field errors. */
    public const string FIELDS = 'fields';

    public function __construct(
        private WriteActions $actions,
        private ActorDirectory $actors,
        private CommandAuthorizer $authorizer,
        private TypeCatalog $types,
        private FieldValidation $fields,
        private ChangesetCommitter $committer,
    ) {}

    public function run(CommandCall $call): WriteResult
    {
        $envelope = $call->envelope;
        $binding = $this->actions->for($call->command);
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

        $plan = $action->plan($call->command, $aggregates);
        $errors = $this->validate($action::class, $plan, $reads);

        if ($errors !== []) {
            return $this->rejected($call, new CatalogError(ErrorCode::ValidationFailed, null, sprintf(
                'The plan breaks %d rule%s of its types; the errors below say which fields to correct.',
                count($errors),
                count($errors) === 1 ? '' : 's',
            )), ...$errors);
        }

        if ($envelope->dryRun) {
            return WriteResult::dryRun(Receipt::dryRun($envelope->waitLevel, RetentionClass::Standard), DryRunReport::of($plan, $reads));
        }

        $outcome = $this->committer->commit(new PendingChangeset($binding->command, $binding->version, $call->command, $envelope, $call->access, $plan, $reads));

        return match (true) {
            $outcome instanceof Committed => WriteResult::committed($outcome->receipt),
            $outcome instanceof VersionConflict => $this->rejected($call, ...array_map(
                fn (StaleRead $stale): CatalogError => $this->conflict($stale, 'changed after it was read'),
                $outcome->stale,
            )),
            $outcome instanceof IdempotencyConflict => $this->rejected($call, new CatalogError(
                ErrorCode::IdempotencyConflict,
                null,
                sprintf('The idempotency key "%s" was used before for this command with other content.', $envelope->idempotencyKey->value),
            )),
            $outcome instanceof IdempotencyInFlight => $this->rejected($call, new CatalogError(
                ErrorCode::IdempotencyInFlight,
                null,
                sprintf('Another call with the idempotency key "%s" is still running.', $envelope->idempotencyKey->value),
            )),
            default => throw InvalidCommandCall::unknownOutcome($outcome::class),
        };
    }

    /**
     * The kernel's rules and the generated validators over the plan (phase 5).
     *
     * @param  class-string  $action
     * @return list<CatalogError>
     */
    private function validate(string $action, Plan $plan, ReadVersions $reads): array
    {
        $errors = [];

        foreach ($plan->mutations() as $mutation) {
            if (! $reads->of($mutation->aggregate()) instanceof ReadVersion) {
                throw InvalidCommandCall::unreadAggregate($action, $mutation->aggregate());
            }

            if (! $mutation instanceof RevisionCreated) {
                continue;
            }

            $type = $this->types->find($mutation->type);

            if (! $type instanceof TypeDefinition) {
                $errors[] = new CatalogError(ErrorCode::ValidationFailed, null, sprintf('No type of this installation has the id %s.', $mutation->type->toString()));

                continue;
            }

            array_push($errors, ...$this->fields->validate($type, $mutation->fields, ValidationStage::Write, new FieldPath(self::FIELDS))->errors);
        }

        return $errors;
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
