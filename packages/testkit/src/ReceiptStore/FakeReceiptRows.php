<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Consistency\UnstorableReceipt;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use DateTimeImmutable;

/**
 * The rules of the ReceiptStore contract as pure functions over the rows of the fake: stored
 * receipts by changeset id. FakeReceiptStore and FakeReceiptSession share them, so a write in
 * autocommit and a write replayed at commit follow the same rules.
 */
#[Internal]
final class FakeReceiptRows
{
    /**
     * The changeset of a receipt the store takes, or UnstorableReceipt.
     */
    public static function storable(Receipt $receipt): ChangesetId
    {
        if (! $receipt->isCommitted() || ! $receipt->changesetId instanceof ChangesetId) {
            throw UnstorableReceipt::notCommitted($receipt->outcome);
        }

        return $receipt->changesetId;
    }

    /**
     * @param  array<string, Receipt>  $rows
     * @return array<string, Receipt>
     */
    public static function stored(array $rows, Receipt $receipt): array
    {
        $changesetId = self::storable($receipt);

        if (isset($rows[$changesetId->toString()])) {
            throw DuplicateReceipt::forChangeset($changesetId);
        }

        $rows[$changesetId->toString()] = $receipt;

        return $rows;
    }

    /**
     * The receipt for the changeset, or null when there is none or it has expired at $now.
     *
     * @param  array<string, Receipt>  $rows
     */
    public static function live(array $rows, ChangesetId $changesetId, DateTimeImmutable $now): ?Receipt
    {
        $receipt = $rows[$changesetId->toString()] ?? null;

        if (! $receipt instanceof Receipt) {
            return null;
        }

        $expiresAt = $receipt->retentionClass->expiresAt($changesetId);

        return $expiresAt instanceof DateTimeImmutable && $now > $expiresAt ? null : $receipt;
    }

    /**
     * The rows with the projection's status recorded, or null when no live receipt for the
     * changeset lists the projection. An acknowledged projection keeps its status.
     *
     * @param  array<string, Receipt>  $rows
     * @return array<string, Receipt>|null
     */
    public static function marked(array $rows, ChangesetId $changesetId, ProjectionStatus $status, DateTimeImmutable $now): ?array
    {
        $receipt = self::live($rows, $changesetId, $now);

        if (! $receipt instanceof Receipt) {
            return null;
        }

        $listed = false;
        $projections = [];

        foreach ($receipt->projections as $current) {
            if (! $current->projection->equals($status->projection)) {
                $projections[] = $current;

                continue;
            }

            $listed = true;
            $projections[] = $current->state === ProjectionState::Acknowledged ? $current : $status;
        }

        if (! $listed) {
            return null;
        }

        $rows[$changesetId->toString()] = new Receipt(
            $receipt->outcome,
            $receipt->changesetId,
            $receipt->waitLevel,
            $receipt->retentionClass,
            $projections,
        );

        return $rows;
    }
}
