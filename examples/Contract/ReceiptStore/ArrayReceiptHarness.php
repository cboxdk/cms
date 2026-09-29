<?php

declare(strict_types=1);

namespace Examples\Contract\ReceiptStore;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\Storage\PartitionMissing;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreHarness;
use Closure;
use DateTimeImmutable;

/**
 * The database behind ArrayReceiptStore: the committed receipts, one row per changeset, the
 * changeset times that no partition covers, and the next transaction's commit position. Every
 * session is a new connection to it.
 */
final class ArrayReceiptHarness implements ReceiptStoreHarness
{
    public const string TABLE = 'receipts';

    /** @var array<string, StoredReceipt> the committed receipts by changeset id */
    private array $committed = [];

    /** @var list<Closure(DateTimeImmutable): bool> the ranges of changeset times no partition covers */
    private array $uncovered = [];

    /** The commit position the next transaction that asks for one gets. */
    private int $nextPosition = 1;

    public function __construct(public readonly Clock $clock) {}

    public function session(): ArrayReceiptSession
    {
        return new ArrayReceiptSession($this);
    }

    public function uncover(DateTimeImmutable $from, DateTimeImmutable $to): void
    {
        $this->uncovered[] = static fn (DateTimeImmutable $at): bool => $at >= $from && $at <= $to;
    }

    /**
     * A new commit position, higher than every one given before, as a database gives a transaction
     * its id when it first needs one.
     */
    public function nextPosition(): CommitPosition
    {
        return new CommitPosition((string) $this->nextPosition++);
    }

    /**
     * The committed row of the changeset.
     */
    public function row(ChangesetId $changesetId): ?StoredReceipt
    {
        return $this->committed[$changesetId->toString()] ?? null;
    }

    /**
     * Commits a row, replacing the one of the same changeset.
     */
    public function put(StoredReceipt $receipt): void
    {
        $this->committed[$receipt->changesetId->toString()] = $receipt;
    }

    /**
     * Puts a new row in its partition, as a database does when it inserts it: a row whose changeset
     * time no partition covers throws PartitionMissing.
     *
     * @throws PartitionMissing
     */
    public function route(StoredReceipt $receipt): void
    {
        $milliseconds = $receipt->changesetId->unixMilliseconds();
        $at = new DateTimeImmutable(sprintf('@%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000));

        if (array_any($this->uncovered, static fn (Closure $uncovered): bool => $uncovered($at))) {
            throw PartitionMissing::forTable(self::TABLE);
        }
    }
}
