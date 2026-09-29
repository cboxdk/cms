<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreContract;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreSession;
use Closure;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

/*
 * The shared ReceiptStore suite must fail a store that breaks the contract. Each broken store
 * wraps the fake and breaks one rule; the named cases must fail on it, and the fake itself must
 * pass every case.
 */

/**
 * @param  Closure(Clock): ReceiptStoreHarness  $harness
 */
function receiptStoreCase(string $name, Closure $harness): InjectedReceiptStoreContract
{
    if ($name === '') {
        throw new LogicException('A shared case has a name.');
    }

    $case = new InjectedReceiptStoreContract($name);
    $case->harness = $harness;

    return $case;
}

/**
 * @return Closure(Clock): ReceiptStoreHarness
 */
function brokenStores(Breach $breach): Closure
{
    return static function (Clock $clock) use ($breach): ReceiptStoreHarness {
        $database = new FakeReceiptStore($clock);

        return new readonly class($database, $breach, new StoredSnapshots) implements ReceiptStoreHarness
        {
            public function __construct(private FakeReceiptStore $database, private Breach $breach, private StoredSnapshots $snapshots) {}

            public function session(): ReceiptStoreSession
            {
                return new BrokenSession($this->database->session(), $this->database, $this->breach, $this->snapshots);
            }

            public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void
            {
                if ($this->breach !== Breach::CoversEveryDate) {
                    $this->database->uncover($from, $to);
                }
            }
        };
    };
}

/**
 * The names of the shared cases.
 *
 * @return list<string>
 */
function receiptStoreCases(): array
{
    $methods = array_filter(
        new ReflectionClass(ReceiptStoreContract::class)->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => $method->getAttributes(Test::class) !== [],
    );

    return array_values(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods));
}

it('passes the fake on every shared case', function (): void {
    $cases = receiptStoreCases();

    foreach ($cases as $name) {
        $case = receiptStoreCase($name, static fn (Clock $clock): ReceiptStoreHarness => new FakeReceiptStore($clock));
        $case->{$name}();
    }

    expect($cases)->toHaveCount(20);
});

it('fails a store that breaks the contract', function (Closure $harness, string $name): void {
    $case = receiptStoreCase($name, $harness);

    expect(fn () => $case->{$name}())->toThrow(AssertionFailedError::class);
})->with([
    'writes that ignore a rollback' => [brokenStores(Breach::IgnoresTransactions), 'a_store_in_a_rolled_back_transaction_is_not_visible'],
    'writes visible before commit' => [brokenStores(Breach::IgnoresTransactions), 'a_store_is_not_visible_to_another_session_until_commit'],
    'marks that ignore a rollback' => [brokenStores(Breach::IgnoresTransactions), 'mark_projection_commits_and_rolls_back_with_the_callers_transaction'],
    'a store that begins a transaction' => [brokenStores(Breach::BeginsTransaction), 'a_store_outside_a_transaction_is_refused_and_stores_nothing'],
    'a store that commits without a transaction' => [brokenStores(Breach::StoresWithoutTransaction), 'a_store_outside_a_transaction_is_refused_and_stores_nothing'],
    'a receipt frozen as it was stored' => [brokenStores(Breach::FreezesStoredReceipt), 'a_receipt_stored_before_the_wait_holds_no_wait_result_and_shows_the_projections_as_marked'],
    'a duplicate that overwrites' => [brokenStores(Breach::OverwritesDuplicate), 'a_second_receipt_for_the_same_changeset_is_refused'],
    'a duplicate of the other class from another transaction that overwrites' => [brokenStores(Breach::OverwritesDuplicate), 'a_receipt_of_either_class_for_a_changeset_another_transaction_stored_is_refused'],
    'a duplicate of the other class from another transaction refused only at commit' => [brokenStores(Breach::RefusesDuplicateAtCommit), 'a_receipt_of_either_class_for_a_changeset_another_transaction_stored_is_refused'],
    'a duplicate of the other class seen by another transaction before commit' => [brokenStores(Breach::IgnoresTransactions), 'a_receipt_of_either_class_for_a_changeset_another_transaction_stored_is_refused'],
    'a duplicate accepted after expiry' => [brokenStores(Breach::OverwritesDuplicate), 'an_expired_receipt_ignores_mark_projection_and_still_holds_its_changeset'],
    'a mark that touches every projection' => [brokenStores(Breach::MarksEveryProjection), 'mark_projection_updates_only_that_projection'],
    'a later acknowledgement that wins' => [brokenStores(Breach::ReacknowledgesProjection), 'an_acknowledged_projection_keeps_its_first_acknowledgement'],
    'a store that never expires' => [static fn (Clock $clock): ReceiptStoreHarness => new FakeReceiptStore(new FakeClock), 'a_standard_receipt_expires_seven_days_after_its_changeset_time'],
    'a store that expires evidence' => [brokenStores(Breach::ExpiresEvidence), 'an_evidence_receipt_does_not_expire'],
    'a store that writes where no partition covers' => [brokenStores(Breach::CoversEveryDate), 'a_store_where_no_partition_covers_the_changeset_throws_partition_missing_and_keeps_nothing'],
    'a failed transaction that keeps its writes' => [brokenStores(Breach::IgnoresTransactions), 'a_store_where_no_partition_covers_the_changeset_throws_partition_missing_and_keeps_nothing'],
    'a failed transaction that takes further calls' => [brokenStores(Breach::KeepsFailedTransactions), 'a_store_where_no_partition_covers_the_changeset_throws_partition_missing_and_keeps_nothing'],
    'a store that takes a receipt at another position' => [brokenStores(Breach::IgnoresPosition), 'a_receipt_with_another_position_than_its_transactions_is_refused_and_stores_nothing'],
    'a store that loses the position' => [brokenStores(Breach::LosesPosition), 'a_receipt_keeps_the_commit_position_of_its_transaction'],
    'transactions that share one position' => [brokenStores(Breach::ReusesPosition), 'a_receipt_keeps_the_commit_position_of_its_transaction'],
]);
