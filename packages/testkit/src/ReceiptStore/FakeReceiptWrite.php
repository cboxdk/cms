<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use DateTimeImmutable;

/**
 * One write in an open transaction of a FakeReceiptSession: a stored receipt, or a projection
 * mark with the time it was made, so replaying it at commit judges expiry as the call did.
 */
#[Internal]
final readonly class FakeReceiptWrite
{
    private function __construct(
        private ?StoredReceipt $receipt,
        private ?ChangesetId $changesetId,
        private ?ProjectionStatus $status,
        private ?DateTimeImmutable $at,
    ) {}

    public static function store(StoredReceipt $receipt): self
    {
        return new self($receipt, null, null, null);
    }

    public static function mark(ChangesetId $changesetId, ProjectionStatus $status, DateTimeImmutable $at): self
    {
        return new self(null, $changesetId, $status, $at);
    }

    /**
     * The rows with this write applied. A store checks for a duplicate as store() does; at commit
     * it finds none, because the session holds the changeset's lock and no other session stored
     * the changeset meanwhile. A mark that no longer matches changes nothing.
     *
     * @param  array<string, StoredReceipt>  $rows
     * @return array<string, StoredReceipt>
     */
    public function applyTo(array $rows): array
    {
        if ($this->receipt instanceof StoredReceipt) {
            return FakeReceiptRows::stored($rows, $this->receipt);
        }

        if ($this->changesetId instanceof ChangesetId && $this->status instanceof ProjectionStatus && $this->at instanceof DateTimeImmutable) {
            return FakeReceiptRows::marked($rows, $this->changesetId, $this->status, $this->at) ?? $rows;
        }

        return $rows;
    }
}
