<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Pipeline\Actions;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Pipeline\Domain\WaitLevelRule;
use Cbox\Cms\Core\Subscriptions\Domain\Pacing;

/**
 * The wait after commit (PRD 8.4): a committed call returns once its changeset has reached the
 * wait level its envelope asks for, by WaitLevelRule, or once the wait budget has passed.
 *
 * The command pipeline calls it after the command transaction has committed, so the wait holds no
 * transaction, no lock and no claim, and nothing it finds can undo the commit (PRD 6.2 phase 8).
 * A level reached at commit, commit itself or a receipt with nothing to wait for, returns at once
 * and reads nothing. Otherwise it reads the receipt from the ReceiptStore, with a growing pause of
 * FIRST_PAUSE_MILLISECONDS up to MAX_PAUSE_MILLISECONDS between reads, until the level is reached
 * or the budget has passed, on real time (Pacing), never the Clock. A level reached gives a
 * committed receipt with the statuses it read; a budget that ran out gives committed_wait_timeout,
 * committed but not waited out, with the statuses it read last. The changeset, its position, its
 * retention class and its consistency token stay the committed receipt's.
 *
 * A receipt that is not committed, a rejection or a dry run, is returned as it is: it has nothing
 * to wait for. So is one already committed_wait_timeout, such as a replay whose level was not
 * reached, because a replay does not wait again (ReplayReceipt).
 */
#[Internal]
final readonly class AwaitWaitLevel
{
    public const int FIRST_PAUSE_MILLISECONDS = 2;

    public const int MAX_PAUSE_MILLISECONDS = 50;

    public function __construct(
        private ReceiptStore $receipts,
        private Pacing $pacing,
        private WaitSettings $settings,
    ) {}

    public function after(Receipt $receipt): Receipt
    {
        $changeset = $receipt->changesetId;
        $position = $receipt->position;

        if ($receipt->outcome !== Outcome::Committed || ! $changeset instanceof ChangesetId || ! $position instanceof CommitPosition || WaitLevelRule::reached($receipt->waitLevel, $receipt->projections)) {
            return $receipt;
        }

        $deadline = $this->pacing->milliseconds() + $this->settings->budgetMilliseconds;
        $pause = self::FIRST_PAUSE_MILLISECONDS;
        $projections = $receipt->projections;

        while (true) {
            $stored = $this->receipts->find($changeset);

            if ($stored instanceof StoredReceipt) {
                $projections = $stored->projections;
            }

            if (WaitLevelRule::reached($receipt->waitLevel, $projections)) {
                return Receipt::committed($changeset, $receipt->waitLevel, $receipt->retentionClass, $position, $projections, $receipt->consistencyToken);
            }

            $left = $deadline - $this->pacing->milliseconds();

            if ($left <= 0) {
                return Receipt::committedWaitTimeout($changeset, $receipt->waitLevel, $receipt->retentionClass, $position, $projections, $receipt->consistencyToken);
            }

            $this->pacing->sleep(min($pause, $left));
            $pause = min($pause * 2, self::MAX_PAUSE_MILLISECONDS);
        }
    }
}
