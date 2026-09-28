<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Idempotency;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Idempotency\ContentHash;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Cbox\Cms\Testkit\Idempotency\HolderEnd;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreContract;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreSession;
use Closure;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

/*
 * The shared IdempotencyStore suite must fail a store that breaks the contract. Each broken store
 * wraps the fake and breaks one rule; the named cases must fail on it, and the fake itself must
 * pass every case.
 */

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
    return static fn (Clock $clock): IdempotencyStoreHarness => new readonly class(new FakeIdempotencyStore($clock), new BrokenIdempotencyState($clock), $breach) implements IdempotencyStoreHarness
    {
        public function __construct(private FakeIdempotencyStore $database, private BrokenIdempotencyState $state, private IdempotencyBreach $breach) {}

        public function session(): IdempotencyStoreSession
        {
            return new BrokenIdempotencySession($this->database->session(), $this->state, $this->breach);
        }

        public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void
        {
            if ($this->breach !== IdempotencyBreach::CoversEveryDate) {
                $this->database->uncover($from, $to);
            }
        }

        public function holdWhileWaiting(IdempotencyScope $scope, IdempotencyKey $key, ContentHash $hash, ?ChangesetId $changesetId, HolderEnd $end, int $afterMilliseconds): void
        {
            $this->database->holdWhileWaiting($scope, $key, $hash, $changesetId, $end, $afterMilliseconds);
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

    expect($cases)->toHaveCount(20);
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
    'a store that writes where no partition covers' => [brokenIdempotencyStores(IdempotencyBreach::CoversEveryDate), 'a_complete_where_no_partition_covers_the_record_throws_partition_missing_and_leaves_the_key_fresh'],
    'a failed transaction that takes further claims' => [brokenIdempotencyStores(IdempotencyBreach::KeepsFailedTransactions), 'a_complete_where_no_partition_covers_the_record_throws_partition_missing_and_leaves_the_key_fresh'],
    'a lookup that ends with the Clock\'s UTC day' => [brokenIdempotencyStores(IdempotencyBreach::LooksUpToTheEndOfTheClocksDay), 'a_record_created_on_a_utc_day_after_the_claim_is_replayed'],
    'an in flight claim that did not wait' => [brokenIdempotencyStores(IdempotencyBreach::GivesUpAtOnce), 'a_claim_held_by_an_open_transaction_is_in_flight_for_another_session'],
    'an in flight claim that did not wait for a holder that outlasts the budget' => [brokenIdempotencyStores(IdempotencyBreach::GivesUpAtOnce), 'a_claim_whose_holder_outlasts_the_budget_is_in_flight_only_after_the_whole_budget'],
    'a claim that does not wait for a holder that commits' => [brokenIdempotencyStores(IdempotencyBreach::GivesUpAtOnce), 'a_claim_that_waits_while_the_holder_completes_and_commits_replays_its_changeset'],
    'a claim that does not wait for a holder that commits another hash' => [brokenIdempotencyStores(IdempotencyBreach::GivesUpAtOnce), 'a_claim_with_another_content_hash_that_waits_while_the_holder_commits_is_a_conflict'],
    'a claim that does not wait for a holder that rolls back' => [brokenIdempotencyStores(IdempotencyBreach::GivesUpAtOnce), 'a_claim_that_waits_while_the_holder_rolls_back_is_fresh_and_holds_the_key'],
    'a replay after a wait that holds nothing' => [brokenIdempotencyStores(IdempotencyBreach::ReleasesReplays), 'a_claim_that_waits_while_the_holder_completes_and_commits_replays_its_changeset'],
    'a conflict after a wait that holds nothing' => [brokenIdempotencyStores(IdempotencyBreach::ReleasesReplays), 'a_claim_with_another_content_hash_that_waits_while_the_holder_commits_is_a_conflict'],
    'a store that never expires' => [static fn (Clock $clock): IdempotencyStoreHarness => new FakeIdempotencyStore(new FakeClock), 'a_completed_key_is_fresh_again_seven_days_after_its_changeset'],
]);
