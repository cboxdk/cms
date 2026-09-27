<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\ProjectionState;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
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
     * @param  array<string, StoredReceipt>  $rows
     * @return array<string, StoredReceipt>
     */
    public static function stored(array $rows, StoredReceipt $receipt): array
    {
        $changesetId = $receipt->changesetId;

        if (isset($rows[$changesetId->toString()])) {
            throw DuplicateReceipt::forChangeset($changesetId);
        }

        $rows[$changesetId->toString()] = $receipt;

        return $rows;
    }

    /**
     * The receipt for the changeset, or null when there is none or it has expired at $now.
     *
     * @param  array<string, StoredReceipt>  $rows
     */
    public static function live(array $rows, ChangesetId $changesetId, DateTimeImmutable $now): ?StoredReceipt
    {
        $receipt = $rows[$changesetId->toString()] ?? null;

        if (! $receipt instanceof StoredReceipt) {
            return null;
        }

        $expiresAt = $receipt->retentionClass->expiresAt($changesetId);

        return $expiresAt instanceof DateTimeImmutable && $now > $expiresAt ? null : $receipt;
    }

    /**
     * The rows with the projection's status recorded, or null when no live receipt for the
     * changeset lists the projection. An acknowledged projection keeps its status.
     *
     * @param  array<string, StoredReceipt>  $rows
     * @return array<string, StoredReceipt>|null
     */
    public static function marked(array $rows, ChangesetId $changesetId, ProjectionStatus $status, DateTimeImmutable $now): ?array
    {
        $receipt = self::live($rows, $changesetId, $now);

        if (! $receipt instanceof StoredReceipt) {
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

        $rows[$changesetId->toString()] = new StoredReceipt($receipt->changesetId, $receipt->retentionClass, $projections);

        return $rows;
    }
}
