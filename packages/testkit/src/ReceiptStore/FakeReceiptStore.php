<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Testkit\Clock\FakeClock;

/**
 * An in-memory receipt store for tests (GUARDRAILS 2.3).
 *
 * Used directly, it behaves like a connection without a transaction: every call commits at once.
 * session() hands out further connections to the same rows, each with begin(), commit() and
 * rollBack(), so a test can run the transactional cases a database store has. A session's writes
 * inside a transaction are visible to that session only, and to everyone after commit.
 *
 * The fake does not model lock waits. Where a database would make a second writer wait, the fake
 * lets it continue and applies the writes in commit order; a duplicate receipt that two sessions
 * both stored then fails at the second commit.
 *
 * Expiry reads the clock, so a test moves a FakeClock past RetentionClass::expiresAt() to expire a
 * Standard receipt.
 */
#[Experimental]
final class FakeReceiptStore implements ReceiptStore, ReceiptStoreHarness
{
    /** @var array<string, Receipt> committed receipts by changeset id */
    private array $rows = [];

    public function __construct(private readonly Clock $clock = new FakeClock) {}

    public function session(): FakeReceiptSession
    {
        return new FakeReceiptSession($this);
    }

    public function store(Receipt $receipt): void
    {
        $this->rows = FakeReceiptRows::stored($this->rows, $receipt);
    }

    public function find(ChangesetId $changesetId): ?Receipt
    {
        return FakeReceiptRows::live($this->rows, $changesetId, $this->clock->now());
    }

    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool
    {
        $rows = FakeReceiptRows::marked($this->rows, $changesetId, $status, $this->clock->now());

        if ($rows === null) {
            return false;
        }

        $this->rows = $rows;

        return true;
    }

    /**
     * The committed rows, for a session to read and replay its writes over.
     *
     * @return array<string, Receipt>
     */
    #[Internal]
    public function committedRows(): array
    {
        return $this->rows;
    }

    /**
     * Replaces the committed rows with a session's result at commit.
     *
     * @param  array<string, Receipt>  $rows
     */
    #[Internal]
    public function commitRows(array $rows): void
    {
        $this->rows = $rows;
    }

    #[Internal]
    public function clock(): Clock
    {
        return $this->clock;
    }
}
