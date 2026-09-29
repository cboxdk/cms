<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ForeignPosition;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Closure;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

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
 * The cases cover storing and finding, the commit position of the transaction that stored a
 * receipt (ReceiptStoreSession::position()), one receipt per changeset across the retention classes and
 * transactions, a stored receipt that holds only the facts of its changeset and no call's wait
 * result, marking projections, typed errors, logical expiry, a changeset time that no partition
 * covers (through ReceiptStoreHarness::uncover()) and the transactions of the caller: a store runs
 * only inside the caller's transaction, throws TransactionRequired without one and never begins
 * one, and a mark runs inside the caller's transaction or commits on its own.
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
        $session = $harness->session();
        $receipts = $session->receipts();

        $expected = [
            $this->receiptWithProjections($ids->next(), RetentionClass::Standard, null, [
                ProjectionStatus::pending(new ProjectionName('search')),
                ProjectionStatus::acknowledged(new ProjectionName('fragments'), $clock->now()),
                ProjectionStatus::pending(new ProjectionName('acme.feed')),
            ]),
            new StoredReceipt(new ChangesetId($ids->next()), RetentionClass::Evidence, $this->unstored(), [
                ProjectionStatus::pending(new ProjectionName('edge')),
            ]),
            new StoredReceipt(new ChangesetId($ids->next()), RetentionClass::Standard, $this->unstored()),
        ];
        $stored = [];

        foreach ($expected as $receipt) {
            $stored[] = $this->storeCommitted($session, $receipt);
        }

        foreach ($stored as $receipt) {
            $this->assertSameReceipt($receipt, $receipts->find($receipt->changesetId));
        }
    }

    #[Test]
    public function a_receipt_keeps_the_commit_position_of_its_transaction(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $writer = $harness->session();
        $reader = $harness->session();

        $writer->begin();
        $position = $writer->position();
        Assert::assertTrue($position->equals($writer->position()), 'The commit position changed within one transaction.');
        $first = $this->storeIn($writer, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));
        Assert::assertTrue($first->position->equals($position));
        $writer->commit();

        $found = $reader->receipts()->find($first->changesetId);
        Assert::assertInstanceOf(StoredReceipt::class, $found);
        Assert::assertSame($position->value, $found->position->value, 'The store did not return the position it stored.');
        $this->assertSameReceipt($first, $found);

        // A transaction that starts after another committed commits at a higher position, so a
        // read whose snapshot is above the later position saw both changesets.
        $second = $this->storeCommitted($writer, $this->receiptWithProjections($ids->next(), RetentionClass::Evidence));
        Assert::assertTrue($first->position->isBelow($second->position), sprintf('The later transaction has the position %s, not above the earlier %s.', $second->position->value, $first->position->value));
        $this->assertSameReceipt($second, $reader->receipts()->find($second->changesetId));
    }

    #[Test]
    public function a_receipt_with_another_position_than_its_transactions_is_refused_and_stores_nothing(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $writer = $harness->session();
        $other = $harness->session();
        $template = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $template->changesetId;

        $other->begin();
        $writer->begin();
        $foreign = [$this->unstored(), new CommitPosition(CommitPosition::MAX), $other->position()];

        foreach ($foreign as $position) {
            try {
                $writer->receipts()->store($this->positioned($template, $position));
                Assert::fail(sprintf('The store took a receipt at position %s in a transaction at %s.', $position->value, $writer->position()->value));
            } catch (ForeignPosition $refused) {
                Assert::assertStringContainsString($changesetId->toString(), $refused->getMessage());
            }

            Assert::assertTrue($writer->inTransaction(), 'store() ended the caller\'s transaction on ForeignPosition.');
            Assert::assertNull($writer->receipts()->find($changesetId), 'A refused receipt was stored.');
        }

        $other->rollBack();

        // The refusal leaves the transaction usable, and the receipt with its own position stores.
        $stored = $this->storeIn($writer, $template);
        $writer->commit();

        $this->assertSameReceipt($stored, $other->receipts()->find($changesetId));
    }

    #[Test]
    public function an_unknown_changeset_is_not_found(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $session = $harness->session();
        $receipts = $session->receipts();

        $this->storeCommitted($session, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));

        Assert::assertNull($receipts->find(new ChangesetId($ids->next())));
    }

    #[Test]
    public function mark_projection_updates_only_that_projection(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $session = $harness->session();
        $receipts = $session->receipts();
        $receipt = $this->storeCommitted($session, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));
        $changesetId = $receipt->changesetId;

        $at = $clock->advance(new DateInterval('PT2S'));

        Assert::assertTrue($receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('fragments'), $at)));
        $this->assertSameReceipt(
            $this->receiptWithProjections($changesetId->value, RetentionClass::Standard, $receipt->position, [
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
        $session = $harness->session();
        $receipts = $session->receipts();
        $receipt = $this->storeCommitted($session, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));
        $changesetId = $receipt->changesetId;

        Assert::assertTrue($receipts->markProjection($changesetId, ProjectionStatus::pending(new ProjectionName('edge'))));
        $this->assertSameReceipt($receipt, $receipts->find($changesetId), 'Marking a pending projection pending changed the receipt.');

        $acknowledged = ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->advance(new DateInterval('PT1S')));

        Assert::assertTrue($receipts->markProjection($changesetId, $acknowledged));
        $once = $this->receiptWithProjections($changesetId->value, RetentionClass::Standard, $receipt->position, [
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
        $session = $harness->session();
        $receipts = $session->receipts();
        $receipt = $this->storeCommitted($session, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));
        $changesetId = $receipt->changesetId;

        $first = $clock->advance(new DateInterval('PT1S'));
        $receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('search'), $first));
        $expected = $this->receiptWithProjections($changesetId->value, RetentionClass::Standard, $receipt->position, [
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
        $session = $harness->session();
        $receipts = $session->receipts();
        $receipt = $this->storeCommitted($session, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));
        $changesetId = $receipt->changesetId;
        $unknown = new ChangesetId($ids->next());

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
        $session = $harness->session();
        $receipts = $session->receipts();
        $receipt = $this->storeCommitted($session, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));
        $changesetId = $receipt->changesetId;

        foreach ([$receipt, new StoredReceipt($changesetId, RetentionClass::Evidence, $this->unstored())] as $second) {
            $session->begin();

            try {
                $receipts->store($this->positioned($second, $session->position()));
                Assert::fail('The store accepted a second receipt for the same changeset.');
            } catch (DuplicateReceipt $duplicate) {
                Assert::assertStringContainsString($changesetId->toString(), $duplicate->getMessage());
            }

            Assert::assertTrue($session->inTransaction(), 'store() ended the caller\'s transaction on DuplicateReceipt.');
            $session->rollBack();

            $this->assertSameReceipt($receipt, $receipts->find($changesetId), 'The refused receipt changed the stored one.');
        }
    }

    #[Test]
    public function a_receipt_of_either_class_for_a_changeset_another_transaction_stored_is_refused(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $reader = $harness->session();

        foreach ([[RetentionClass::Standard, RetentionClass::Evidence], [RetentionClass::Evidence, RetentionClass::Standard]] as [$firstClass, $secondClass]) {
            $first = $harness->session();
            $second = $harness->session();
            $template = $this->receiptWithProjections($ids->next(), $firstClass);
            $changesetId = $template->changesetId;
            $label = sprintf('%s, then %s', $firstClass->value, $secondClass->value);

            // Both transactions are open at once, and the second stores after the first commits.
            // The contract refuses the duplicate at store(), which the kernel calls in the command
            // transaction, and never at commit(): a store that waits for the first transaction, as
            // a database store does, sees its receipt once it commits.
            $first->begin();
            $receipt = $this->storeIn($first, $template);
            $second->begin();
            Assert::assertNull($second->receipts()->find($changesetId), "Another session sees an uncommitted receipt ({$label}).");
            $first->commit();
            $refused = false;

            try {
                $this->storeIn($second, new StoredReceipt($changesetId, $secondClass, $this->unstored(), [
                    ProjectionStatus::pending(new ProjectionName('edge')),
                ]));
            } catch (DuplicateReceipt $duplicate) {
                Assert::assertStringContainsString($changesetId->toString(), $duplicate->getMessage());
                $refused = true;
            }

            if (! $refused) {
                try {
                    $second->commit();
                } catch (DuplicateReceipt) {
                    Assert::fail("The store refused a second receipt for a changeset another transaction stored at commit(); the contract refuses it at store() ({$label}).");
                }

                Assert::fail("The store kept a second receipt for a changeset another transaction stored ({$label}).");
            }

            Assert::assertTrue($second->inTransaction(), "store() ended the caller's transaction on DuplicateReceipt ({$label}).");
            $second->rollBack();

            $this->assertSameReceipt($receipt, $reader->receipts()->find($changesetId), "The refused receipt changed the stored one ({$label}).");
        }
    }

    #[Test]
    public function a_receipt_stored_before_the_wait_holds_no_wait_result_and_shows_the_projections_as_marked(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $writer = $harness->session();
        $reader = $harness->session();

        // A call that asked for the wait level origin: the kernel stores the receipt in the command
        // transaction (PRD 6.2 phase 7), while the fragments projection is still pending, and waits
        // only after the commit. Nothing stored may say that origin was reached.
        $receipt = $this->storeCommitted($writer, new StoredReceipt(new ChangesetId($ids->next()), RetentionClass::Standard, $this->unstored(), [
            ProjectionStatus::pending(new ProjectionName('fragments')),
        ]));
        $changesetId = $receipt->changesetId;

        $found = $reader->receipts()->find($changesetId);
        Assert::assertInstanceOf(StoredReceipt::class, $found);
        $fields = array_keys(get_object_vars($found));
        sort($fields);
        Assert::assertSame(
            ['changesetId', 'position', 'projections', 'retentionClass'],
            $fields,
            'The stored receipt holds more than the facts of its changeset, such as an outcome or a wait level.',
        );
        $this->assertSameReceipt($receipt, $found, 'The receipt found before the projection marked is not the pending one.');

        // What a replay finds after the projection marked is the projection's status now.
        $acknowledged = ProjectionStatus::acknowledged(new ProjectionName('fragments'), $clock->advance(new DateInterval('PT1S')));
        Assert::assertTrue($writer->receipts()->markProjection($changesetId, $acknowledged));
        $this->assertSameReceipt(
            new StoredReceipt($changesetId, RetentionClass::Standard, $receipt->position, [$acknowledged]),
            $reader->receipts()->find($changesetId),
            'The receipt found after the projection marked does not show the acknowledgement.',
        );
    }

    #[Test]
    public function a_standard_receipt_expires_seven_days_after_its_changeset_time(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $session = $harness->session();
        $receipts = $session->receipts();
        $receipt = $this->storeCommitted($session, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));
        $changesetId = $receipt->changesetId;
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
        $session = $harness->session();
        $receipts = $session->receipts();
        $receipt = $this->storeCommitted($session, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));
        $changesetId = $receipt->changesetId;

        $clock->set($this->changesetTime($changesetId)->add(new DateInterval('P7DT1S')));

        Assert::assertFalse($receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now())));

        $session->begin();

        try {
            $receipts->store($this->positioned($receipt, $session->position()));
            Assert::fail('The store accepted a second receipt for a changeset whose receipt expired but was not removed.');
        } catch (DuplicateReceipt) {
            $session->rollBack();
        }

        Assert::assertNull($receipts->find($changesetId));
    }

    #[Test]
    public function an_evidence_receipt_does_not_expire(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $session = $harness->session();
        $receipts = $session->receipts();
        $receipt = $this->storeCommitted($session, $this->receiptWithProjections($ids->next(), RetentionClass::Evidence));
        $changesetId = $receipt->changesetId;
        $time = $this->changesetTime($changesetId);

        foreach (['P7DT1S', 'P30D', 'P10Y'] as $later) {
            $clock->set($time->add(new DateInterval($later)));
            $this->assertSameReceipt($receipt, $receipts->find($changesetId), "The evidence receipt expired after {$later}.");
        }

        Assert::assertTrue($receipts->markProjection($changesetId, ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now())));
    }

    #[Test]
    public function a_store_where_no_partition_covers_the_changeset_throws_partition_missing_and_keeps_nothing(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $writer = $harness->session();
        $reader = $harness->session();
        $start = $clock->now();
        $covered = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);

        $day = $start->setTime(0, 0)->add(new DateInterval('P2Y'));
        $harness->uncover($day, $day->setTime(23, 59, 59, 999_999));
        $clock->set($day->setTime(12, 0, 0, 250_000));
        $uncovered = [
            $this->receiptWithProjections($ids->next(), RetentionClass::Standard),
            new StoredReceipt(new ChangesetId($ids->next()), RetentionClass::Evidence, $this->unstored()),
        ];

        foreach ($uncovered as $receipt) {
            $writer->begin();
            $this->assertPartitionMissing(function () use ($writer, $receipt): void {
                $this->storeIn($writer, $receipt);
            }, sprintf('The store took a %s receipt whose changeset time no partition covers.', $receipt->retentionClass->value));
            Assert::assertTrue($writer->inTransaction(), 'store() ended the caller\'s transaction on PartitionMissing.');
            $writer->rollBack();
            Assert::assertNull($reader->receipts()->find($receipt->changesetId), 'A store() that no partition covered left a receipt.');
        }

        $writer->begin();
        $this->storeIn($writer, $covered);
        $this->assertPartitionMissing(function () use ($writer, $uncovered): void {
            $this->storeIn($writer, $uncovered[0]);
        }, 'The store took a receipt no partition covers inside a transaction.');
        Assert::assertTrue($writer->inTransaction(), 'store() ended the caller\'s transaction on PartitionMissing.');
        $refused = false;

        try {
            $writer->receipts()->find($covered->changesetId);
        } catch (Throwable) {
            $refused = true;
        }

        Assert::assertTrue($refused, 'The transaction took another statement after PartitionMissing; it has failed and only rolls back.');
        $writer->rollBack();

        $clock->set($start);
        Assert::assertNull($writer->receipts()->find($covered->changesetId), 'The rollback after PartitionMissing kept the transaction\'s earlier receipt.');
        Assert::assertNull($reader->receipts()->find($uncovered[0]->changesetId), 'The rollback after PartitionMissing kept the receipt no partition covers.');

        $covered = $this->storeCommitted($writer, $covered);
        $this->assertSameReceipt($covered, $reader->receipts()->find($covered->changesetId), 'The receipt could not be stored again after the rollback.');
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
        $changesetId = $receipt->changesetId;

        $writer->begin();
        $receipt = $this->storeIn($writer, $receipt);
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
        $changesetId = $receipt->changesetId;

        $writer->begin();
        $receipt = $this->storeIn($writer, $receipt);

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
        $receipt = $this->storeCommitted($reader, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));
        $changesetId = $receipt->changesetId;
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
            $this->receiptWithProjections($changesetId->value, RetentionClass::Standard, $receipt->position, [
                ProjectionStatus::pending(new ProjectionName('edge')),
                $acknowledged,
                ProjectionStatus::pending(new ProjectionName('search')),
            ]),
            $reader->receipts()->find($changesetId),
            'Another session does not see the committed mark.',
        );
    }

    #[Test]
    public function a_store_outside_a_transaction_is_refused_and_stores_nothing(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $writer = $harness->session();
        $reader = $harness->session();
        $receipt = $this->receiptWithProjections($ids->next(), RetentionClass::Standard);
        $changesetId = $receipt->changesetId;
        $refused = false;

        // The receipt commits with its changeset, in the caller's command transaction. A store that
        // commits on its own, or opens a transaction of its own, would need a lock that outlives
        // its statements to keep one receipt per changeset.
        try {
            $writer->receipts()->store($receipt);
        } catch (TransactionRequired) {
            $refused = true;
        }

        Assert::assertTrue($refused, 'The store took a receipt without the caller\'s transaction; store() runs only inside it.');
        Assert::assertFalse($writer->inTransaction(), 'store() began a transaction.');
        Assert::assertNull($reader->receipts()->find($changesetId), 'A store() without a transaction left a receipt.');

        // Nothing of the refused store holds the changeset, on this session or another.
        $receipt = $this->storeCommitted($reader, $receipt);
        $this->assertSameReceipt($receipt, $writer->receipts()->find($changesetId), 'The receipt could not be stored in a transaction after a store() without one.');
    }

    #[Test]
    public function mark_projection_never_begins_a_transaction(): void
    {
        $clock = new FakeClock;
        $harness = $this->receiptStores($clock);
        $ids = new FakeIdGenerator(clock: $clock);
        $writer = $harness->session();
        $reader = $harness->session();
        $receipt = $this->storeCommitted($writer, $this->receiptWithProjections($ids->next(), RetentionClass::Standard));
        $changesetId = $receipt->changesetId;

        $acknowledged = ProjectionStatus::acknowledged(new ProjectionName('edge'), $clock->now());
        $writer->receipts()->markProjection($changesetId, $acknowledged);
        Assert::assertFalse($writer->inTransaction(), 'markProjection() left a transaction open.');
        $this->assertSameReceipt(
            $this->receiptWithProjections($changesetId->value, RetentionClass::Standard, $receipt->position, [
                $acknowledged,
                ProjectionStatus::pending(new ProjectionName('fragments')),
                ProjectionStatus::pending(new ProjectionName('search')),
            ]),
            $reader->receipts()->find($changesetId),
            'A mark without a transaction is not visible to another session at once.',
        );
    }

    /**
     * Stores the receipt, at the commit position of the session's transaction, in a transaction of
     * its own on the session and commits it, as the command kernel does in the command
     * transaction. Returns the receipt as stored.
     */
    private function storeCommitted(ReceiptStoreSession $session, StoredReceipt $receipt): StoredReceipt
    {
        $session->begin();

        try {
            $stored = $this->storeIn($session, $receipt);
        } catch (Throwable $failed) {
            $session->rollBack();

            throw $failed;
        }

        $session->commit();

        return $stored;
    }

    /**
     * Stores the receipt at the commit position of the session's open transaction, as the command
     * kernel stores it, and returns the receipt as stored.
     */
    private function storeIn(ReceiptStoreSession $session, StoredReceipt $receipt): StoredReceipt
    {
        $stored = $this->positioned($receipt, $session->position());
        $session->receipts()->store($stored);

        return $stored;
    }

    /**
     * The receipt at another commit position.
     */
    private function positioned(StoredReceipt $receipt, CommitPosition $position): StoredReceipt
    {
        return new StoredReceipt($receipt->changesetId, $receipt->retentionClass, $position, $receipt->projections);
    }

    /**
     * The position of a receipt the suite has not stored yet: 0, which no transaction has, so a
     * store that took it unchanged fails the suite. storeIn() gives the receipt its transaction's.
     */
    private function unstored(): CommitPosition
    {
        return new CommitPosition('0');
    }

    /**
     * A receipt with the given position and projections, by default not stored yet and with
     * fragments, edge and search, all pending.
     *
     * @param  list<ProjectionStatus>|null  $projections
     */
    private function receiptWithProjections(Uuid7 $id, RetentionClass $retention, ?CommitPosition $position = null, ?array $projections = null): StoredReceipt
    {
        return new StoredReceipt(new ChangesetId($id), $retention, $position ?? $this->unstored(), $projections ?? [
            ProjectionStatus::pending(new ProjectionName('fragments')),
            ProjectionStatus::pending(new ProjectionName('edge')),
            ProjectionStatus::pending(new ProjectionName('search')),
        ]);
    }

    /**
     * Runs the call and asserts that it throws PartitionMissing with its error code.
     *
     * @param  Closure(): void  $call
     */
    private function assertPartitionMissing(Closure $call, string $message): void
    {
        try {
            $call();
        } catch (PartitionMissing $missing) {
            Assert::assertSame('partition_missing', PartitionMissing::CODE);
            Assert::assertStringStartsWith('[partition_missing] ', $missing->getMessage());

            return;
        }

        Assert::fail($message);
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

    private function assertSameReceipt(?StoredReceipt $expected, ?StoredReceipt $actual, string $message = ''): void
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
    private function describeReceipt(?StoredReceipt $receipt): array
    {
        if (! $receipt instanceof StoredReceipt) {
            return ['none'];
        }

        $lines = [sprintf('%s %s at %s', $receipt->changesetId->toString(), $receipt->retentionClass->value, $receipt->position->value)];

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
