<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Core\IdempotencyStore\Adapter\ClaimLock;
use Cbox\Cms\Core\ReceiptStore\Adapter\ReceiptLock;

/*
 * The advisory lock key of a changeset's store. The encoding is fixed: a changed lock key would let
 * processes on the old and the new code store receipts of one changeset at once, so the vectors
 * are pinned.
 */

it('derives the digest and the lock key from the versioned encoding of the changeset id', function (string $changesetId, string $digest, int $lockKey): void {
    $lock = ReceiptLock::of(ChangesetId::fromString($changesetId));

    expect($lock->digest)->toBe($digest)
        ->and($lock->digest)->toBe(hash('sha256', sprintf('["cbox_cms.receipt.v1","%s"]', $changesetId)))
        ->and($lock->key)->toBe($lockKey)
        ->and(sprintf('%016x', $lock->key))->toBe(substr($digest, 0, 16));
})->with([
    'a positive lock key' => ['019b76da-a801-7000-8000-000000000000', '1113b33521aaadf94cc66ae2e7d87743bb7bcc60c26722aa1051e9da48d2b98f', 1230524163981749753],
    'a negative lock key, the top bit set' => ['019b76da-a800-7000-8000-000000000000', 'b3c3fcc9c78cf0bd1150c9cf2c2b155f055566a79931069ded52e314b2fbd284', -5493269176895344451],
]);

it('gives every changeset its own key, and a prefix apart from the idempotency claims', function (): void {
    $ids = [
        '019b76da-a800-7000-8000-000000000000',
        '019b76da-a800-7000-8000-000000000001',
        '019b76da-a801-7000-8000-000000000000',
        '019b76da-a802-7000-8000-000000000000',
    ];
    $locks = array_map(static fn (string $id): ReceiptLock => ReceiptLock::of(ChangesetId::fromString($id)), $ids);

    expect(array_unique(array_map(static fn (ReceiptLock $lock): string => $lock->digest, $locks)))->toHaveCount(count($ids))
        ->and(array_unique(array_map(static fn (ReceiptLock $lock): int => $lock->key, $locks)))->toHaveCount(count($ids))
        ->and(ReceiptLock::of(ChangesetId::fromString($ids[0])))->toEqual($locks[0])
        ->and(ReceiptLock::VERSION)->toBe('cbox_cms.receipt.v1')
        ->and(ReceiptLock::VERSION)->not->toBe(ClaimLock::VERSION);
});
