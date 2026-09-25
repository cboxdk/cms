<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\UnstorableReceipt;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;

/**
 * The shared contract suite for ReceiptStore (GUARDRAILS 2.3 and 9). The FakeReceiptStore and
 * every real store run the same cases.
 *
 * Use the trait in a PHPUnit test class in the package's tests/Contract directory and return a
 * harness for a new, empty store from receiptStores(). Every store the harness hands out reads the
 * time from the given clock, which the cases move:
 *
 *     final class FakeReceiptStoreContractTest extends TestCase
 *     {
 *         use ReceiptStoreContract;
 *
 *         protected function receiptStores(Clock $clock): ReceiptStoreHarness
 *         {
 *             return new FakeReceiptStore($clock);
 *         }
 *     }
 *
 * The cases cover storing and finding, marking projections, typed errors, logical expiry and the
 * transactions of the caller: a store runs inside the caller's transaction and never begins one.
 */
#[Experimental]
trait ReceiptStoreContract
{
    /**
     * A harness for a new, empty store under test whose sessions read the time from $clock.
     */
    abstract protected function receiptStores(Clock $clock): ReceiptStoreHarness;

    #[Test]
    public function a_stored_receipt_is_found_equal(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $receipts = $harness->session()->receipts();

        $expected = [
            $this->receiptWithProjections($ids->next(), RetentionClass::Standard, [
                ProjectionStatus::pending(new ProjectionName('search')),
                ProjectionStatus::acknowledged(new ProjectionName('fragments'), $clock->now()),
                ProjectionStatus::pending(new ProjectionName('acme.feed')),
            ]),
            Receipt::committedWaitTimeout(new ChangesetId($ids->next()), WaitLevel::Edge, RetentionClass::Evidence, [
                ProjectionStatus::pending(new ProjectionName('edge')),
            ]),
            Receipt::committed(new ChangesetId($ids->next()), WaitLevel::Commit, RetentionClass::Standard),
        ];

        foreach ($expected as $receipt) {
            $receipts->store($receipt);
        }

        foreach ($expected as $receipt) {
            $this->assertSameReceipt($receipt, $receipts->find($this->changesetOf($receipt)));
        }
    }

    #[Test]
    public function an_unknown_changeset_is_not_found(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $receipts = $harness->session()->receipts();

        $receipts->store($this->receiptWithProjections($ids->next(), RetentionClass::Standard));

        Assert::assertNull($receipts->find(new ChangesetId($ids->next())));
    }

    #[Test]
    public function mark_projection_updates_only_that_projection(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $receipts = $harness->session()->receipts();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);
        $receipts->store($receipt);

        $at = $clock->advance(new DateInterval('PT2S'));

        Assert::assertTrue($receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('fragments'), $at)));
        $this->assertSameReceipt(
            $this->receiptWithProjections($changesetId->value, RetentionClass::Standard, [
                ProjectionStatus::acknowledged(new ProjectionName('fragments'), $at),
                ProjectionStatus::pending(new ProjectionName('edge')),
                ProjectionStatus::pending(new ProjectionName('search')),
            ]),
            $receipts->find($changesetId),
        );
    }

    #[Test]
    public function marking_a_projection_twice_with_the_same_status_is_a_no_op(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $receipts = $harness->session()->receipts();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);
        $receipts->store($receipt);

        Assert::assertTrue($receipts->markProjection($changesetId, ProjectionStatus::pending(new ProjectionName('edge'))));
        $this->assertSameReceipt($receipt, $receipts->find($changesetId), 'Marking a pending projection pending changed the receipt.');

        $acknowledged = ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->advance(new DateInterval('PT1S')));

        Assert::assertTrue($receipts->markProjection($changesetId, $acknowledged));
        $once = $this->receiptWithProjections($changesetId->value, RetentionClass::Standard, [
            $acknowledged,
            ProjectionStatus::pending(new ProjectionName('fragments')),
            ProjectionStatus::pending(new ProjectionName('search')),
        ]);
        $this->assertSameReceipt($once, $receipts->find($changesetId), 'The first mark did not acknowledge the projection.');

        Assert::assertTrue($receipts->markProjection($changesetId, $acknowledged));
        $this->assertSameReceipt($once, $receipts->find($changesetId), 'The second identical mark changed the receipt.');
    }

    #[Test]
    public function an_acknowledged_projection_keeps_its_first_acknowledgement(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $receipts = $harness->session()->receipts();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);
        $receipts->store($receipt);

        $first = $clock->advance(new DateInterval('PT1S'));
        $receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('search'), $first));
        $expected = $this->receiptWithProjections($changesetId->value, RetentionClass::Standard, [
            ProjectionStatus::pending(new ProjectionName('edge')),
            ProjectionStatus::pending(new ProjectionName('fragments')),
            ProjectionStatus::acknowledged(new ProjectionName('search'), $first),
        ]);
        $this->assertSameReceipt($expected, $receipts->find($changesetId));

        $later = $clock->advance(new DateInterval('PT1M'));

        Assert::assertTrue($receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('search'), $later)));
        Assert::assertTrue($receipts->markProjection($changesetId, ProjectionStatus::pending(new ProjectionName('search'))));
        $this->assertSameReceipt($expected, $receipts->find($changesetId), 'A later mark changed an acknowledged projection.');
    }

    #[Test]
    public function mark_projection_changes_nothing_for_an_unknown_changeset_or_an_unlisted_projection(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $receipts = $harness->session()->receipts();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);
        $unknown = new ChangesetId($ids->next());
        $receipts->store($receipt);

        Assert::assertFalse($receipts->markProjection($unknown, ProjectionStatus::acknowledged(new ProjectionName('fragments'), $clock->now())));
        Assert::assertNull($receipts->find($unknown), 'Marking a projection created a receipt.');

        Assert::assertFalse($receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('acme.feed'), $clock->now())));
        $this->assertSameReceipt($receipt, $receipts->find($changesetId), 'Marking an unlisted projection changed the receipt.');
    }

    #[Test]
    public function a_second_receipt_for_the_same_changeset_is_refused(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $receipts = $harness->session()->receipts();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);
        $receipts->store($receipt);

        foreach ([$receipt, Receipt::committed($changesetId, WaitLevel::Propagated, RetentionClass::Evidence)] as $second) {
            try {
                $receipts->store($second);
                Assert::fail('The store accepted a second receipt for the same changeset.');
            } catch (DuplicateReceipt $duplicate) {
                Assert::assertStringContainsString($changesetId->toString(), $duplicate->getMessage());
            }

            $this->assertSameReceipt($receipt, $receipts->find($changesetId), 'The refused receipt changed the stored one.');
        }
    }

    #[Test]
    public function rejected_and_dry_run_receipts_are_refused(): void
    {
        $harness = $this->receiptStores(new FakeClock);
        $receipts = $harness->session()->receipts();

        foreach ([Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard), Receipt::dryRun(WaitLevel::Origin, RetentionClass::Evidence)] as $receipt) {
            try {
                $receipts->store($receipt);
                Assert::fail(sprintf('The store accepted a %s receipt.', $receipt->outcome->value));
            } catch (UnstorableReceipt $unstorable) {
                Assert::assertStringContainsString($receipt->outcome->value, $unstorable->getMessage());
            }
        }
    }

    #[Test]
    public function a_standard_receipt_expires_seven_days_after_its_changeset_time(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $receipts = $harness->session()->receipts();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);
        $receipts->store($receipt);
        $expiry = $this->changesetTime($changesetId)->add(new DateInterval('P7D'));

        $clock->set($expiry->modify('-1 day'));
        $this->assertSameReceipt($receipt, $receipts->find($changesetId), 'The receipt expired before seven days.');

        $clock->set($expiry);
        $this->assertSameReceipt($receipt, $receipts->find($changesetId), 'The receipt expired at exactly seven days.');

        $clock->set($expiry->modify('+1 millisecond'));
        Assert::assertNull($receipts->find($changesetId), 'The receipt was found a millisecond after it expired.');

        $clock->set($expiry->modify('+1 second'));
        Assert::assertNull($receipts->find($changesetId), 'The receipt was found seven days and one second after its changeset.');
    }

    #[Test]
    public function an_expired_receipt_ignores_mark_projection_and_still_holds_its_changeset(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $receipts = $harness->session()->receipts();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);
        $receipts->store($receipt);

        $clock->set($this->changesetTime($changesetId)->add(new DateInterval('P7DT1S')));

        Assert::assertFalse($receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now())));

        try {
            $receipts->store($receipt);
            Assert::fail('The store accepted a second receipt for a changeset whose receipt expired but was not removed.');
        } catch (DuplicateReceipt) {
            Assert::assertNull($receipts->find($changesetId));
        }
    }

    #[Test]
    public function an_evidence_receipt_does_not_expire(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $receipts = $harness->session()->receipts();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Evidence);
        $changesetId = $this->changesetOf($receipt);
        $receipts->store($receipt);
        $time = $this->changesetTime($changesetId);

        foreach (['P7DT1S', 'P30D', 'P10Y'] as $later) {
            $clock->set($time->add(new DateInterval($later)));
            $this->assertSameReceipt($receipt, $receipts->find($changesetId), "The evidence receipt expired after {$later}.");
        }

        Assert::assertTrue($receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now())));
    }

    #[Test]
    public function a_store_in_a_rolled_back_transaction_is_not_visible(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $writer = $harness->session();
        $reader = $harness->session();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);

        $writer->begin();
        $writer->receipts()->store($receipt);
        $this->assertSameReceipt($receipt, $writer->receipts()->find($changesetId), 'The writer does not see its own uncommitted receipt.');
        $writer->rollBack();

        Assert::assertFalse($writer->inTransaction());
        Assert::assertNull($writer->receipts()->find($changesetId), 'The writer still sees a receipt it rolled back.');
        Assert::assertNull($reader->receipts()->find($changesetId), 'Another session sees a receipt that was rolled back.');
    }

    #[Test]
    public function a_store_is_not_visible_to_another_session_until_commit(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $writer = $harness->session();
        $reader = $harness->session();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);

        $writer->begin();
        $writer->receipts()->store($receipt);

        Assert::assertTrue($writer->inTransaction(), 'store() ended the caller\'s transaction.');
        Assert::assertNull($reader->receipts()->find($changesetId), 'Another session sees an uncommitted receipt.');

        $writer->commit();

        $this->assertSameReceipt($receipt, $reader->receipts()->find($changesetId), 'Another session does not see the committed receipt.');
    }

    #[Test]
    public function mark_projection_commits_and_rolls_back_with_the_callers_transaction(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $writer = $harness->session();
        $reader = $harness->session();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);
        $reader->receipts()->store($receipt);
        $acknowledged = ProjectionStatus::acknowledged(new ProjectionName('fragments'), $clock->advance(new DateInterval('PT1S')));

        $writer->begin();
        Assert::assertTrue($writer->receipts()->markProjection($changesetId, $acknowledged));
        Assert::assertTrue($writer->inTransaction(), 'markProjection() ended the caller\'s transaction.');
        $this->assertSameReceipt($receipt, $reader->receipts()->find($changesetId), 'Another session sees an uncommitted mark.');
        $writer->rollBack();

        $this->assertSameReceipt($receipt, $writer->receipts()->find($changesetId), 'A rolled back mark is still visible.');

        $writer->begin();
        $writer->receipts()->markProjection($changesetId, $acknowledged);
        $writer->commit();

        $this->assertSameReceipt(
            $this->receiptWithProjections($changesetId->value, RetentionClass::Standard, [
                ProjectionStatus::pending(new ProjectionName('edge')),
                $acknowledged,
                ProjectionStatus::pending(new ProjectionName('search')),
            ]),
            $reader->receipts()->find($changesetId),
            'Another session does not see the committed mark.',
        );
    }

    #[Test]
    public function store_and_mark_projection_never_begin_a_transaction(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $writer = $harness->session();
        $reader = $harness->session();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $this->changesetOf($receipt);

        $writer->receipts()->store($receipt);
        Assert::assertFalse($writer->inTransaction(), 'store() left a transaction open.');
        $this->assertSameReceipt($receipt, $reader->receipts()->find($changesetId), 'A store without a transaction is not visible to another session at once.');

        $acknowledged = ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now());
        $writer->receipts()->markProjection($changesetId, $acknowledged);
        Assert::assertFalse($writer->inTransaction(), 'markProjection() left a transaction open.');
        $this->assertSameReceipt(
            $this->receiptWithProjections($changesetId->value, RetentionClass::Standard, [
                $acknowledged,
                ProjectionStatus::pending(new ProjectionName('fragments')),
                ProjectionStatus::pending(new ProjectionName('search')),
            ]),
            $reader->receipts()->find($changesetId),
            'A mark without a transaction is not visible to another session at once.',
        );
    }

    /**
     * A Committed receipt with the given projections, by default fragments, edge and search, all
     * pending.
     *
     * @param  list<ProjectionStatus>|null  $projections
     */
    private function receiptWithProjections(Uuid7 $id, RetentionClass $retention, ?array $projections = null): Receipt
    {
        return Receipt::committed(new ChangesetId($id), WaitLevel::Origin, $retention, $projections ?? [
            ProjectionStatus::pending(new ProjectionName('fragments')),
            ProjectionStatus::pending(new ProjectionName('edge')),
            ProjectionStatus::pending(new ProjectionName('search')),
        ]);
    }

    private function changesetOf(Receipt $receipt): ChangesetId
    {
        return $receipt->changesetId ?? Assert::fail('The fixture receipt has no changeset.');
    }

    /**
     * The time in the changeset id, computed here and not with RetentionClass::expiresAt(), so a
     * mistake there shows up in this suite.
     */
    private function changesetTime(ChangesetId $changesetId): DateTimeImmutable
    {
        $milliseconds = $changesetId->unixMilliseconds();

        return new DateTimeImmutable(sprintf('@%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000));
    }

    private function assertSameReceipt(?Receipt $expected, ?Receipt $actual, string $message = ''): void
    {
        Assert::assertNotNull($actual, $message !== '' ? $message : 'The store did not find the receipt.');
        Assert::assertSame($this->describeReceipt($expected), $this->describeReceipt($actual), $message);
        Assert::assertEquals($expected, $actual, $message);
    }

    /**
     * Every field of a receipt as text, times with microseconds, for an exact comparison.
     *
     * @return list<string>
     */
    private function describeReceipt(?Receipt $receipt): array
    {
        if (! $receipt instanceof Receipt) {
            return ['none'];
        }

        $lines = [sprintf(
            '%s %s %s %s',
            $receipt->outcome->value,
            $receipt->changesetId?->toString() ?? '-',
            $receipt->waitLevel->value,
            $receipt->retentionClass->value,
        )];

        foreach ($receipt->projections as $status) {
            $lines[] = sprintf(
                '%s %s %s',
                $status->projection->value,
                $status->state->value,
                $status->acknowledgedAt?->format('Y-m-d\TH:i:s.u e') ?? '-',
            );
        }

        return $lines;
    }
}
