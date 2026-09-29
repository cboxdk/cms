<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Consistency;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\ConsistencyToken;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\LogSequenceNumber;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\Receipt;

/*
 * The consistency token a receipt carries (PRD 8.5): the failover generation, a Postgres timeline
 * id, and the WAL position of the commit as Postgres writes a pg_lsn. Only a committed receipt has
 * one.
 */

function token(): ConsistencyToken
{
    return new ConsistencyToken(2, new LogSequenceNumber('16/B374D848'));
}

function changeset(): ChangesetId
{
    return ChangesetId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02');
}

it('takes a WAL position as Postgres writes it', function (string $value): void {
    expect(new LogSequenceNumber($value)->value)->toBe($value);
})->with(['0/0', '0/1', '16/B374D848', 'FFFFFFFF/FFFFFFFF']);

it('refuses a WAL position in another form', function (string $value): void {
    expect(static fn (): LogSequenceNumber => new LogSequenceNumber($value))
        ->toThrow(InvalidReceipt::class, sprintf('A WAL position is two groups of 1 to 8 uppercase hex digits separated by a slash, as Postgres writes a pg_lsn, for example "16/B374D848", got "%s".', $value));
})->with(['', '16/b374d848', '16', '/1', '1/', '123456789/0', '0/123456789', '0/1 ', ' 0/1', '0//1', 'G/1']);

it('takes a generation from 1 to the largest timeline id', function (int $generation): void {
    expect(new ConsistencyToken($generation, new LogSequenceNumber('0/1'))->generation)->toBe($generation);
})->with([1, 2, ConsistencyToken::MAX_GENERATION]);

it('refuses a generation that is not a timeline id', function (int $generation): void {
    expect(static fn (): ConsistencyToken => new ConsistencyToken($generation, new LogSequenceNumber('0/1')))
        ->toThrow(InvalidReceipt::class, sprintf('A failover generation is a Postgres timeline id, 1 to 4294967295, got %d.', $generation));
})->with([0, -1, ConsistencyToken::MAX_GENERATION + 1]);

it('compares tokens and positions by value', function (): void {
    expect(token()->equals(new ConsistencyToken(2, new LogSequenceNumber('16/B374D848'))))->toBeTrue()
        ->and(token()->equals(new ConsistencyToken(3, new LogSequenceNumber('16/B374D848'))))->toBeFalse()
        ->and(token()->equals(new ConsistencyToken(2, new LogSequenceNumber('16/B374D849'))))->toBeFalse()
        ->and(new LogSequenceNumber('0/1')->equals(new LogSequenceNumber('0/1')))->toBeTrue()
        ->and(new LogSequenceNumber('0/1')->equals(new LogSequenceNumber('0/2')))->toBeFalse();
});

it('gives a committed receipt its consistency token, and none by default', function (): void {
    $position = new CommitPosition('4827');

    expect(Receipt::committed(changeset(), WaitLevel::Commit, RetentionClass::Standard, $position, [], token())->consistencyToken)->toEqual(token())
        ->and(Receipt::committedWaitTimeout(changeset(), WaitLevel::Edge, RetentionClass::Standard, $position, [], token())->consistencyToken)->toEqual(token())
        ->and(Receipt::committed(changeset(), WaitLevel::Commit, RetentionClass::Standard, $position)->consistencyToken)->toBeNull()
        ->and(Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard)->consistencyToken)->toBeNull();
});

it('refuses a consistency token on a receipt that committed nothing', function (string $outcome): void {
    expect(static fn (): Receipt => new Receipt(Outcome::from($outcome), null, WaitLevel::Commit, RetentionClass::Standard, [], consistencyToken: token()))
        ->toThrow(InvalidReceipt::class, sprintf('A %s receipt has no consistency token: the command committed nothing.', $outcome));
})->with(['rejected', 'dry_run']);
