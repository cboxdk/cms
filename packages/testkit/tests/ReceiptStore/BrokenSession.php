<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptSession;
use Cbox\Cms\Testkit\ReceiptStore\FakeReceiptStore;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreSession;
use DateTimeImmutable;
use LogicException;
use Throwable;

/**
 * A session of the fake with one rule broken.
 */
final class BrokenSession implements ReceiptStore, ReceiptStoreSession
{
    private bool $open = false;

    /** The DuplicateReceipt that store() held back for commit(), for Breach::RefusesDuplicateAtCommit. */
    private ?DuplicateReceipt $heldBack = null;

    public function __construct(
        private readonly FakeReceiptSession $inner,
        private readonly FakeReceiptStore $database,
        private readonly Breach $breach,
        private readonly StoredSnapshots $snapshots,
    ) {}

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
        $heldBack = $this->heldBack;
        $this->heldBack = null;

        if ($heldBack instanceof DuplicateReceipt) {
            $this->inner->rollBack();

            throw $heldBack;
        }

        $this->breach === Breach::IgnoresTransactions ? $this->open = false : $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->heldBack = null;
        $this->breach === Breach::IgnoresTransactions ? $this->open = false : $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->breach === Breach::IgnoresTransactions ? $this->open : $this->inner->inTransaction();
    }

    public function store(StoredReceipt $receipt): void
    {
        if ($this->breach === Breach::BeginsTransaction && ! $this->inner->inTransaction()) {
            $this->inner->begin();
        }

        // Without a transaction of the inner session, these breaches store and commit at once.
        if (in_array($this->breach, [Breach::IgnoresTransactions, Breach::StoresWithoutTransaction], true) && ! $this->inner->inTransaction()) {
            $this->inner->begin();

            try {
                $this->storeInner($receipt);
            } catch (Throwable $failed) {
                $this->inner->rollBack();

                throw $failed;
            }

            $this->inner->commit();

            return;
        }

        $this->storeInner($receipt);
    }

    public function find(ChangesetId $changesetId): ?StoredReceipt
    {
        try {
            $receipt = $this->inner->find($changesetId);
        } catch (LogicException $failed) {
            if ($this->breach !== Breach::KeepsFailedTransactions) {
                throw $failed;
            }

            return null;
        }

        if ($this->breach === Breach::ExpiresEvidence && $this->database->clock()->now() > RetentionClass::Standard->expiresAt($changesetId)) {
            return null;
        }

        if ($this->breach === Breach::FreezesStoredReceipt && $receipt instanceof StoredReceipt) {
            return $this->snapshots->receipts[$changesetId->toString()] ?? $receipt;
        }

        return $receipt;
    }

    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool
    {
        $receipt = $this->inner->find($changesetId);

        if ($this->breach === Breach::MarksEveryProjection && $receipt instanceof StoredReceipt) {
            foreach ($receipt->projections as $current) {
                $this->inner->markProjection($changesetId, new ProjectionStatus($current->projection, $status->state, $status->acknowledgedAt));
            }

            return true;
        }

        if ($this->breach === Breach::ReacknowledgesProjection && $receipt instanceof StoredReceipt && $status->acknowledgedAt instanceof DateTimeImmutable) {
            $projections = array_map(
                static fn (ProjectionStatus $current): ProjectionStatus => $current->projection->equals($status->projection) ? $status : $current,
                $receipt->projections,
            );
            $rows = $this->database->committedRows();
            $rows[$changesetId->toString()] = new StoredReceipt($receipt->changesetId, $receipt->retentionClass, $projections);
            $this->database->commitRows($rows);

            return true;
        }

        return $this->inner->markProjection($changesetId, $status);
    }

    private function storeInner(StoredReceipt $receipt): void
    {
        try {
            $this->inner->store($receipt);
            $this->snapshots->receipts[$receipt->changesetId->toString()] = $receipt;
        } catch (DuplicateReceipt $duplicate) {
            if ($this->breach === Breach::RefusesDuplicateAtCommit && $this->inner->inTransaction()) {
                $this->heldBack = $duplicate;

                return;
            }

            if ($this->breach !== Breach::OverwritesDuplicate) {
                throw $duplicate;
            }

            $rows = $this->database->committedRows();
            $rows[$receipt->changesetId->toString()] = $receipt;
            $this->database->commitRows($rows);
        }
    }
}
