<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use LogicException;
use Override;

/**
 * A receipt store that answers every find() with one stored receipt, or none, and counts the
 * finds; the wait after commit only reads.
 */
final class CountingReceipts implements ReceiptStore
{
    public int $finds = 0;

    public function __construct(private readonly ?StoredReceipt $stored) {}

    #[Override]
    public function store(StoredReceipt $receipt): void
    {
        throw new LogicException('The wait after commit stores no receipt.');
    }

    #[Override]
    public function find(ChangesetId $changesetId): ?StoredReceipt
    {
        $this->finds++;

        return $this->stored;
    }

    #[Override]
    public function markProjection(ChangesetId $changesetId, ProjectionStatus $status): bool
    {
        throw new LogicException('The wait after commit marks no projection.');
    }
}
