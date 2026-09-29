<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Fakes;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Core\Pipeline\Domain\ChangesetCommitter;
use Cbox\Cms\Core\Pipeline\Domain\CommitOutcome;
use Cbox\Cms\Core\Pipeline\Domain\Dto\Committed;
use Cbox\Cms\Core\Pipeline\Domain\Dto\PendingChangeset;
use Cbox\Cms\Testkit\ReceiptStore\ReceiptStoreSession;
use Override;
use Throwable;

/**
 * Records every pending changeset it is handed, and throws what a test gives it, answers with the
 * outcome a test gives it, or by default commits it at the wait level the envelope asks for: as
 * the changeset CHANGESET, or with the next id of the IdGenerator it is given. Given a ReceiptStoreSession, a default commit
 * stores the changeset's StoredReceipt there, at the session's commit position and with the
 * projections a test lists, as the real commit stores it in the command transaction; without
 * one, the receipt carries the position POSITION.
 */
final class FakeChangesetCommitter implements ChangesetCommitter
{
    public const string CHANGESET = '01936f5e-8a2b-7c3d-9e4f-0000000000c5';

    /** The commit position of the receipt it answers with when it is given no ReceiptStoreSession. */
    public const string POSITION = '4827';

    /** @var list<PendingChangeset> */
    public array $pending = [];

    /**
     * @param  list<ProjectionStatus>  $projections
     */
    public function __construct(
        private readonly ?CommitOutcome $outcome = null,
        private readonly ?ReceiptStoreSession $receipts = null,
        private readonly ?IdGenerator $ids = null,
        private readonly array $projections = [],
        private readonly ?Throwable $throws = null,
    ) {}

    #[Override]
    public function commit(PendingChangeset $changeset): CommitOutcome
    {
        $this->pending[] = $changeset;

        if ($this->throws instanceof Throwable) {
            throw $this->throws;
        }

        if ($this->outcome instanceof CommitOutcome) {
            return $this->outcome;
        }

        $id = $this->ids instanceof IdGenerator
            ? new ChangesetId($this->ids->next())
            : ChangesetId::fromString(self::CHANGESET);

        $position = $this->receipts instanceof ReceiptStoreSession
            ? $this->receipts->position()
            : new CommitPosition(self::POSITION);

        $this->receipts?->receipts()->store(new StoredReceipt($id, RetentionClass::Standard, $position, $this->projections));

        return new Committed(Receipt::committed($id, $changeset->envelope->waitLevel, RetentionClass::Standard, $position, $this->projections));
    }
}
