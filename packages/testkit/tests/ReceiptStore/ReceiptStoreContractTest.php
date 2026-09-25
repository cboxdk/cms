<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\UnstorableReceipt;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptSession;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreContract;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreSession;
use Closure;
use DateTimeImmutable;
use LogicException;
use Override;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/*
 * The shared ReceiptStore suite must fail a store that breaks the contract. Each broken store
 * wraps the fake and breaks one rule; the named cases must fail on it, and the fake itself must
 * pass every case.
 */

/**
 * How a broken store breaks the contract.
 */
enum Breach
{
    /** begin, commit and rollBack do nothing, so every write commits at once. */
    case IgnoresTransactions;

    /** store() opens a transaction when none is open and leaves it open. */
    case BeginsTransaction;

    /** A Rejected or DryRun receipt is dropped without an error. */
    case SwallowsUnstorable;

    /** A second receipt for a changeset replaces the first without an error. */
    case OverwritesDuplicate;

    /** markProjection() gives every projection in the receipt the new status. */
    case MarksEveryProjection;

    /** A later acknowledgement replaces the first one. */
    case ReacknowledgesProjection;

    /** An Evidence receipt expires like a Standard one. */
    case ExpiresEvidence;
}

/**
 * A session of the fake with one rule broken.
 */
final class BrokenSession implements ReceiptStore, ReceiptStoreSession
{
    private bool $open = false;

    public function __construct(private readonly FakeReceiptSession $inner, private readonly FakeReceiptStore $database, private readonly Breach $breach) {}

    public function receipts(): ReceiptStore
    {
        return $this;
    }

    public function begin(): void
    {
        $this->breach === Breach::IgnoresTransactions ? $this->open = true : $this->inner->begin();
    }

    public function commit(): void
    {
        $this->breach === Breach::IgnoresTransactions ? $this->open = false : $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->breach === Breach::IgnoresTransactions ? $this->open = false : $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->breach === Breach::IgnoresTransactions ? $this->open : $this->inner->inTransaction();
    }

    public function store(Receipt $receipt): void
    {
        if ($this->breach === Breach::BeginsTransaction && ! $this->inner->inTransaction()) {
            $this->inner->begin();
        }

        try {
            $this->inner->store($receipt);
        } catch (UnstorableReceipt $unstorable) {
            if ($this->breach !== Breach::SwallowsUnstorable) {
                throw $unstorable;
            }
        } catch (DuplicateReceipt $duplicate) {
            if ($this->breach !== Breach::OverwritesDuplicate) {
                throw $duplicate;
            }

            $rows = $this->database->committedRows();
            $rows[$receipt->changesetId?->toString() ?? ''] = $receipt;
            $this->database->commitRows($rows);
        }
    }

    public function find(ChangesetId $changesetId): ?Receipt
    {
        $receipt = $this->inner->find($changesetId);

        if ($this->breach === Breach::ExpiresEvidence && $this->database->clock()->now() > RetentionClass::Standard->expiresAt($changesetId)) {
            return null;
        }

        return $receipt;
    }

    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool
    {
        $receipt = $this->inner->find($changesetId);

        if ($this->breach === Breach::MarksEveryProjection && $receipt instanceof Receipt) {
            foreach ($receipt->projections as $current) {
                $this->inner->markProjection($changesetId, new ProjectionStatus($current->projection, $status->state, $status->acknowledgedAt));
            }

            return true;
        }

        if ($this->breach === Breach::ReacknowledgesProjection && $receipt instanceof Receipt && $status->acknowledgedAt instanceof DateTimeImmutable) {
            $projections = array_map(
                static fn (ProjectionStatus $current): ProjectionStatus => $current->projection->equals($status->projection) ? $status : $current,
                $receipt->projections,
            );
            $rows = $this->database->committedRows();
            $rows[$changesetId->toString()] = new Receipt($receipt->outcome, $receipt->changesetId, $receipt->waitLevel, $receipt->retentionClass, $projections);
            $this->database->commitRows($rows);

            return true;
        }

        return $this->inner->markProjection($changesetId, $status);
    }
}

/**
 * The contract suite with the harness under test injected.
 */
final class InjectedReceiptStoreContract extends TestCase
{
    use ReceiptStoreContract;

    /** @var (Closure(Clock): ReceiptStoreHarness)|null */
    public ?Closure $harness = null;

    #[Override]
    protected function receiptStores(Clock $clock): ReceiptStoreHarness
    {
        $harness = $this->harness ?? throw new LogicException('No harness was injected.');

        return $harness($clock);
    }
}

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

        return new readonly class($database, $breach) implements ReceiptStoreHarness
        {
            public function __construct(private FakeReceiptStore $database, private Breach $breach) {}

            public function session(): ReceiptStoreSession
            {
                return new BrokenSession($this->database->session(), $this->database, $this->breach);
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

    expect($cases)->toHaveCount(15);
});

it('fails a store that breaks the contract', function (Closure $harness, string $name): void {
    $case = receiptStoreCase($name, $harness);

    expect(fn () => $case->{$name}())->toThrow(AssertionFailedError::class);
})->with([
    'writes that ignore a rollback' => [brokenStores(Breach::IgnoresTransactions), 'a_store_in_a_rolled_back_transaction_is_not_visible'],
    'writes visible before commit' => [brokenStores(Breach::IgnoresTransactions), 'a_store_is_not_visible_to_another_session_until_commit'],
    'marks that ignore a rollback' => [brokenStores(Breach::IgnoresTransactions), 'mark_projection_commits_and_rolls_back_with_the_callers_transaction'],
    'a store that begins a transaction' => [brokenStores(Breach::BeginsTransaction), 'store_and_mark_projection_never_begin_a_transaction'],
    'a rejected receipt dropped silently' => [brokenStores(Breach::SwallowsUnstorable), 'rejected_and_dry_run_receipts_are_refused'],
    'a duplicate that overwrites' => [brokenStores(Breach::OverwritesDuplicate), 'a_second_receipt_for_the_same_changeset_is_refused'],
    'a duplicate accepted after expiry' => [brokenStores(Breach::OverwritesDuplicate), 'an_expired_receipt_ignores_mark_projection_and_still_holds_its_changeset'],
    'a mark that touches every projection' => [brokenStores(Breach::MarksEveryProjection), 'mark_projection_updates_only_that_projection'],
    'a later acknowledgement that wins' => [brokenStores(Breach::ReacknowledgesProjection), 'an_acknowledged_projection_keeps_its_first_acknowledgement'],
    'a store that never expires' => [static fn (Clock $clock): ReceiptStoreHarness => new FakeReceiptStore(new FakeClock), 'a_standard_receipt_expires_seven_days_after_its_changeset_time'],
    'a store that expires evidence' => [brokenStores(Breach::ExpiresEvidence), 'an_evidence_receipt_does_not_expire'],
]);
