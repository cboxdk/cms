<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Core\Pipeline\Actions\AwaitWaitLevel;
use Cbox\Cms\Core\Pipeline\Domain\Dto\WaitSettings;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\CountingReceipts;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakePacing;

/*
 * The wait after commit apart from the pipeline: a receipt that has nothing to wait for, including
 * one that already timed out, is returned at once without a read, and the last millisecond of the
 * budget is waited out before the wait gives up.
 */

const AWAITED_CHANGESET = '0192a0c0-0000-7000-8000-00000000d0c1';

function awaitedReceipt(Outcome $outcome = Outcome::Committed): Receipt
{
    $pending = [ProjectionStatus::pending(new ProjectionName('origin'))];
    $changeset = ChangesetId::fromString(AWAITED_CHANGESET);

    return $outcome === Outcome::Committed
        ? Receipt::committed($changeset, WaitLevel::Origin, RetentionClass::Standard, new CommitPosition('900'), $pending)
        : Receipt::committedWaitTimeout($changeset, WaitLevel::Origin, RetentionClass::Standard, new CommitPosition('900'), $pending);
}

it('returns a receipt that already timed out as it is, without reading the store', function (): void {
    $receipts = new CountingReceipts(null);
    $receipt = awaitedReceipt(Outcome::CommittedWaitTimeout);

    expect(new AwaitWaitLevel($receipts, new FakePacing, new WaitSettings(1000))->after($receipt))->toBe($receipt)
        ->and($receipts->finds)->toBe(0);
});

it('returns a committed receipt whose level is reached at commit without reading the store', function (): void {
    $receipts = new CountingReceipts(null);
    $receipt = Receipt::committed(ChangesetId::fromString(AWAITED_CHANGESET), WaitLevel::Commit, RetentionClass::Standard, new CommitPosition('900'));

    expect(new AwaitWaitLevel($receipts, new FakePacing, new WaitSettings(1000))->after($receipt))->toBe($receipt)
        ->and($receipts->finds)->toBe(0);
});

it('waits out the last millisecond of the budget before it gives up', function (): void {
    $receipts = new CountingReceipts(new StoredReceipt(ChangesetId::fromString(AWAITED_CHANGESET), RetentionClass::Standard, new CommitPosition('900'), [ProjectionStatus::pending(new ProjectionName('origin'))]));
    $pacing = new FakePacing;

    $answer = new AwaitWaitLevel($receipts, $pacing, new WaitSettings(1))->after(awaitedReceipt());

    expect($answer->outcome)->toBe(Outcome::CommittedWaitTimeout)
        ->and($pacing->sleeps())->toBe([1])
        ->and($receipts->finds)->toBe(2);
});
