<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\ReceiptStore;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use DateTimeImmutable;

/**
 * One write in an open transaction of a FakeReceiptSession: a stored receipt, or a projection
 * mark with the time it was made, so replaying it at commit judges expiry as the call did.
 */
#[Internal]
final readonly class FakeReceiptWrite
{
    private function __construct(
        private ?Receipt $receipt,
        private ?ChangesetId $changesetId,
        private ?ProjectionStatus $status,
        private ?DateTimeImmutable $at,
    ) {}

    public static function store(Receipt $receipt): self
    {
        return new self($receipt, null, null, null);
    }

    public static function mark(ChangesetId $changesetId, ProjectionStatus $status, DateTimeImmutable $at): self
    {
        return new self(null, $changesetId, $status, $at);
    }

    /**
     * The rows with this write applied. A store that meets a receipt committed meanwhile throws
     * DuplicateReceipt; a mark that no longer matches changes nothing.
     *
     * @param  array<string, Receipt>  $rows
     * @return array<string, Receipt>
     */
    public function applyTo(array $rows): array
    {
        if ($this->receipt instanceof Receipt) {
            return FakeReceiptRows::stored($rows, $this->receipt);
        }

        if ($this->changesetId instanceof ChangesetId && $this->status instanceof ProjectionStatus && $this->at instanceof DateTimeImmutable) {
            return FakeReceiptRows::marked($rows, $this->changesetId, $this->status, $this->at) ?? $rows;
        }

        return $rows;
    }
}
