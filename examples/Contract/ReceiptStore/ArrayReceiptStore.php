<?php

declare(strict_types=1);

namespace Examples\Contract\ReceiptStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ForeignPosition;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use DateTimeImmutable;

/**
 * A replacement receipt store, kept in PHP arrays so that the example needs no services. It runs
 * on the caller's connection, an ArrayReceiptSession, and never begins or ends a transaction. A
 * receipt is stored only inside the caller's transaction, at that transaction's commit position.
 */
final readonly class ArrayReceiptStore implements ReceiptStore
{
    public function __construct(
        private ArrayReceiptSession $connection,
        private Clock $clock,
    ) {}

    public function store(StoredReceipt $receipt): void
    {
        if (! $this->connection->inTransaction()) {
            throw TransactionRequired::forStore();
        }

        $position = $this->connection->position();

        if (! $receipt->position->equals($position)) {
            throw ForeignPosition::forReceipt($receipt->changesetId, $receipt->position, $position);
        }

        // An expired receipt still holds its changeset until its partition is dropped.
        $this->connection->write(
            $receipt->changesetId,
            static fn (?StoredReceipt $row): StoredReceipt => $row instanceof StoredReceipt
                ? throw DuplicateReceipt::forChangeset($receipt->changesetId)
                : $receipt,
        );
    }

    public function find(ChangesetId $changesetId): ?StoredReceipt
    {
        return self::live($this->connection->row($changesetId), $this->clock->now());
    }

    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool
    {
        $now = $this->clock->now();

        if (! self::marked($this->connection->row($changesetId), $status, $now) instanceof StoredReceipt) {
            return false;
        }

        $this->connection->write(
            $changesetId,
            static fn (?StoredReceipt $row): ?StoredReceipt => self::marked($row, $status, $now) ?? $row,
        );

        return true;
    }

    /**
     * The receipt, or null when there is none or the clock is past its expiry.
     */
    private static function live(?StoredReceipt $receipt, DateTimeImmutable $now): ?StoredReceipt
    {
        if (! $receipt instanceof StoredReceipt) {
            return null;
        }

        $expiresAt = $receipt->retentionClass->expiresAt($receipt->changesetId);

        return ! $expiresAt instanceof DateTimeImmutable || $now <= $expiresAt ? $receipt : null;
    }

    /**
     * The receipt with the projection marked, or null when the receipt is not live or does not
     * list the projection. An acknowledgement is final, so an acknowledged projection stays as it
     * is.
     */
    private static function marked(?StoredReceipt $row, ProjectionStatus $status, DateTimeImmutable $now): ?StoredReceipt
    {
        $receipt = self::live($row, $now);

        if (! $receipt instanceof StoredReceipt) {
            return null;
        }

        $projections = [];
        $listed = false;

        foreach ($receipt->projections as $current) {
            if ($current->projection->equals($status->projection)) {
                $listed = true;
                $current = $current->state === ProjectionState::Acknowledged ? $current : $status;
            }

            $projections[] = $current;
        }

        return $listed ? new StoredReceipt($receipt->changesetId, $receipt->retentionClass, $receipt->position, $projections) : null;
    }
}
