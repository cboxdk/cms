<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Idempotency;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Contracts\Idempotency\ClaimToken;
use Cbox\Cms\Contracts\Idempotency\Conflict;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\Fresh;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Idempotency\InFlight;
use Cbox\Cms\Contracts\Idempotency\InvalidClaim;
use Cbox\Cms\Contracts\Idempotency\Replay;
use Cbox\Cms\Contracts\Idempotency\WaitBudget;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencySession;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreContract;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreSession;
use Closure;
use LogicException;
use Override;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/*
 * The shared IdempotencyStore suite must fail a store that breaks the contract. Each broken store
 * wraps the fake and breaks one rule; the named cases must fail on it, and the fake itself must
 * pass every case.
 */

/**
 * How a broken store breaks the contract.
 */
enum IdempotencyBreach
{
    /** complete() commits at once and opens a new transaction, so the record ignores a rollback. */
    case CommitsCompleteAtOnce;

    /** complete() records nothing, so a key is never replayed. */
    case ForgetsRecords;

    /** A Fresh claim is released at once, so another transaction can take the key. */
    case ReleasesFreshClaims;

    /** A Fresh claim that is not completed stays held after its transaction ends. */
    case KeepsClaimsWithoutComplete;

    /** A Replay or Conflict does not hold the claim. */
    case ReleasesReplays;

    /** The same key with another hash replays the stored changeset. */
    case IgnoresHash;

    /** Keys are unique across all actors, sources and command types. */
    case IgnoresScope;

    /** complete() accepts a token this transaction does not hold. */
    case AcceptsAnyToken;

    /** claim() and complete() open a transaction when none is open. */
    case RunsOutsideTransactions;
}

/**
 * What the broken sessions of one store share.
 */
final class BrokenIdempotencyState
{
    /** @var array<string, ChangesetId> */
    public array $completed = [];

    /** @var array<string, BrokenIdempotencySession> */
    public array $stuck = [];
}

/**
 * A session of the fake with one rule broken.
 */
final readonly class BrokenIdempotencySession implements IdempotencyStore, IdempotencyStoreSession
{
    public function __construct(private FakeIdempotencySession $inner, private BrokenIdempotencyState $state, private IdempotencyBreach $breach) {}

    public function idempotency(): IdempotencyStore
    {
        return $this;
    }

    public function begin(): void
    {
        $this->inner->begin();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function claim(IdempotencyScope $scope, IdempotencyKey $key, ContentHash $hash, WaitBudget $waitBudget): ClaimResult
    {
        $this->beginWhenBroken();

        if ($this->breach === IdempotencyBreach::IgnoresScope) {
            $scope = IdempotencyScope::forActor('everyone', 'any.command');
        }

        $name = FakeIdempotencyStore::claimName($scope, $key);
        $stuck = $this->state->stuck[$name] ?? null;

        if ($stuck instanceof BrokenIdempotencySession && $stuck !== $this) {
            return new InFlight($scope, $key, $waitBudget);
        }

        $result = $this->inner->claim($scope, $key, $hash, $waitBudget);

        if ($this->breach === IdempotencyBreach::IgnoresHash && $result instanceof Conflict && isset($this->state->completed[$name])) {
            return new Replay($this->state->completed[$name]);
        }

        if ($result instanceof Fresh && $this->breach === IdempotencyBreach::KeepsClaimsWithoutComplete) {
            $this->state->stuck[$name] = $this;
        }

        if (($result instanceof Fresh && $this->breach === IdempotencyBreach::ReleasesFreshClaims)
            || (($result instanceof Replay || $result instanceof Conflict) && $this->breach === IdempotencyBreach::ReleasesReplays)) {
            $this->inner->commit();
            $this->inner->begin();
        }

        return $result;
    }

    public function complete(ClaimToken $token, ChangesetId $changesetId): void
    {
        $this->beginWhenBroken();
        $name = FakeIdempotencyStore::claimName($token->scope, $token->key);

        if ($this->breach === IdempotencyBreach::ReleasesFreshClaims) {
            $this->inner->claim($token->scope, $token->key, $token->hash, WaitBudget::none());
        }

        if ($this->breach === IdempotencyBreach::ForgetsRecords) {
            return;
        }

        try {
            $this->inner->complete($token, $changesetId);
        } catch (InvalidClaim $invalid) {
            if ($this->breach !== IdempotencyBreach::AcceptsAnyToken) {
                throw $invalid;
            }

            return;
        }

        $this->state->completed[$name] = $changesetId;
        unset($this->state->stuck[$name]);

        if ($this->breach === IdempotencyBreach::CommitsCompleteAtOnce) {
            $this->inner->commit();
            $this->inner->begin();
        }
    }

    private function beginWhenBroken(): void
    {
        if ($this->breach === IdempotencyBreach::RunsOutsideTransactions && ! $this->inner->inTransaction()) {
            $this->inner->begin();
        }
    }
}

/**
 * The contract suite with the harness under test injected.
 */
final class InjectedIdempotencyStoreContract extends TestCase
{
    use IdempotencyStoreContract;

    /** @var (Closure(Clock): IdempotencyStoreHarness)|null */
    public ?Closure $harness = null;

    #[Override]
    protected function idempotencyStores(Clock $clock): IdempotencyStoreHarness
    {
        $harness = $this->harness ?? throw new LogicException('No harness was injected.');

        return $harness($clock);
    }
}

/**
 * @param  Closure(Clock): IdempotencyStoreHarness  $harness
 */
function idempotencyStoreCase(string $name, Closure $harness): InjectedIdempotencyStoreContract
{
    if ($name === '') {
        throw new LogicException('A shared case has a name.');
    }

    $case = new InjectedIdempotencyStoreContract($name);
    $case->harness = $harness;

    return $case;
}

/**
 * @return Closure(Clock): IdempotencyStoreHarness
 */
function brokenIdempotencyStores(IdempotencyBreach $breach): Closure
{
    return static fn (Clock $clock): IdempotencyStoreHarness => new readonly class(new FakeIdempotencyStore($clock), new BrokenIdempotencyState, $breach) implements IdempotencyStoreHarness
    {
        public function __construct(private FakeIdempotencyStore $database, private BrokenIdempotencyState $state, private IdempotencyBreach $breach) {}

        public function session(): IdempotencyStoreSession
        {
            return new BrokenIdempotencySession($this->database->session(), $this->state, $this->breach);
        }
    };
}

/**
 * The names of the shared cases.
 *
 * @return list<string>
 */
function idempotencyStoreCases(): array
{
    $methods = array_filter(
        new ReflectionClass(IdempotencyStoreContract::class)->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => $method->getAttributes(Test::class) !== [],
    );

    return array_values(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods));
}

it('passes the fake on every shared case', function (): void {
    $cases = idempotencyStoreCases();

    foreach ($cases as $name) {
        $case = idempotencyStoreCase($name, static fn (Clock $clock): IdempotencyStoreHarness => new FakeIdempotencyStore($clock));
        $case->{$name}();
    }

    expect($cases)->toHaveCount(14);
});

it('fails a store that breaks the contract', function (Closure $harness, string $name): void {
    $case = idempotencyStoreCase($name, $harness);

    expect(fn () => $case->{$name}())->toThrow(AssertionFailedError::class);
})->with([
    'a completed record that ignores a rollback' => [brokenIdempotencyStores(IdempotencyBreach::CommitsCompleteAtOnce), 'a_claim_completed_in_a_rolled_back_transaction_leaves_the_key_fresh'],
    'a completed record visible before commit' => [brokenIdempotencyStores(IdempotencyBreach::CommitsCompleteAtOnce), 'a_completed_claim_is_in_flight_until_its_transaction_commits'],
    'a store that never replays' => [brokenIdempotencyStores(IdempotencyBreach::ForgetsRecords), 'a_completed_and_committed_claim_replays_its_changeset'],
    'five claims that do not replay' => [brokenIdempotencyStores(IdempotencyBreach::ForgetsRecords), 'five_claims_with_the_same_key_and_hash_after_one_completed_commit_all_replay'],
    'a fresh claim that holds nothing' => [brokenIdempotencyStores(IdempotencyBreach::ReleasesFreshClaims), 'a_claim_held_by_an_open_transaction_is_in_flight_for_another_session'],
    'a claim that outlives its transaction' => [brokenIdempotencyStores(IdempotencyBreach::KeepsClaimsWithoutComplete), 'a_fresh_claim_committed_without_complete_leaves_the_key_fresh'],
    'a replay that holds nothing' => [brokenIdempotencyStores(IdempotencyBreach::ReleasesReplays), 'every_result_but_in_flight_holds_the_claim_until_the_transaction_ends'],
    'another hash that replays' => [brokenIdempotencyStores(IdempotencyBreach::IgnoresHash), 'the_same_key_with_another_content_hash_is_a_conflict'],
    'keys without scope' => [brokenIdempotencyStores(IdempotencyBreach::IgnoresScope), 'the_same_key_under_another_command_type_or_principal_is_independent'],
    'a complete() that takes any token' => [brokenIdempotencyStores(IdempotencyBreach::AcceptsAnyToken), 'complete_needs_a_fresh_claim_held_by_the_transaction'],
    'a store that opens its own transaction' => [brokenIdempotencyStores(IdempotencyBreach::RunsOutsideTransactions), 'claim_and_complete_need_an_open_transaction'],
    'a store that never expires' => [static fn (Clock $clock): IdempotencyStoreHarness => new FakeIdempotencyStore(new FakeClock), 'a_completed_key_is_fresh_again_seven_days_after_its_changeset'],
]);
