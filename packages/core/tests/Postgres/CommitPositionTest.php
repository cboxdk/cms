<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Events\EventPosition;
use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Core\Consistency\Infrastructure\TransactionPosition;
use Cbox\Cms\Core\Events\Infrastructure\EventWriter;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Core\Tests\Events\Fixtures\CounterRaised;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The commit position (PRD 7.4, 8.4, 8.12): a changeset's position is the xid8 of its transaction,
 * the one its receipt and its events carry, and a read's position is the xmin of its snapshot. A
 * read at position R saw every changeset whose position is below R: a read that starts after the
 * commit gets a snapshot whose xmin passes the position, and one that started before it does not.
 */

afterEach(function (): void {
    app(IndependentConnections::class)->closeAll();
});

/**
 * The read position and the snapshot of the transaction open on $connection, as text.
 *
 * @return array{CommitPosition, string}
 */
function readPosition(Connection $connection): array
{
    $row = $connection->selectOne('select pg_snapshot_xmin(pg_current_snapshot())::text as xmin, pg_current_snapshot()::text as snapshot');
    $xmin = is_object($row) && property_exists($row, 'xmin') ? $row->xmin : null;
    $snapshot = is_object($row) && property_exists($row, 'snapshot') ? $row->snapshot : null;

    if (! is_string($xmin) || ! is_string($snapshot)) {
        throw new LogicException('Expected the snapshot xmin and the snapshot as text.');
    }

    return [new CommitPosition($xmin), $snapshot];
}

/**
 * Whether the snapshot shows the work of the transaction at $position as committed.
 */
function visibleIn(Connection $connection, CommitPosition $position, string $snapshot): bool
{
    return $connection->scalar('select pg_visible_in_snapshot(?::xid8, ?::pg_snapshot)', [$position->value, $snapshot]) === true;
}

/**
 * Opens a REPEATABLE READ transaction on $connection, so one snapshot, taken by its first
 * statement, serves every read in it.
 */
function beginRead(Connection $connection): void
{
    $connection->beginTransaction();
    $connection->statement('set transaction isolation level repeatable read');
}

it('gives a read that starts after the commit a snapshot xmin above the changeset\'s position, and one that started before none', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
    [$writer, $before, $after] = app(IndependentConnections::class)->open(3);
    $store = new PostgresReceiptStore(app(DatabaseManager::class), $clock, $writer->getName());

    $writer->beginTransaction();
    $receipt = ReceiptTables::at($writer, ReceiptTables::receipt('2026-01-01T00:00:00Z'));
    $store->store($receipt);
    $position = $receipt->position;

    // Before the commit: the writer is still running, so no snapshot's xmin can pass it.
    beginRead($before);
    [$readBefore, $snapshotBefore] = readPosition($before);

    expect($position->seenBy($readBefore))->toBeFalse()
        ->and($readBefore->isBelow($position) || $readBefore->equals($position))->toBeTrue()
        ->and(visibleIn($before, $position, $snapshotBefore))->toBeFalse();

    $writer->commit();

    // After the commit: a new snapshot sees the changeset. Its xmin passes the position once no
    // transaction that began before the commit is still running; one in another database of the
    // cluster may hold it back for a moment, and never past the command transaction's limit.
    $deadline = hrtime(true) + 5_000_000_000;

    do {
        beginRead($after);
        [$readAfter, $snapshotAfter] = readPosition($after);

        if ($position->seenBy($readAfter)) {
            break;
        }

        expect(visibleIn($after, $position, $snapshotAfter))->toBeTrue();
        $after->rollBack();
        usleep(20_000);
    } while (hrtime(true) < $deadline);

    if (! $position->seenBy($readAfter)) {
        throw new AssertionFailedError(sprintf('No snapshot taken after the commit had an xmin above the position %s within five seconds; the last was %s.', $position->value, $readAfter->value));
    }

    $afterStore = new PostgresReceiptStore(app(DatabaseManager::class), $clock, $after->getName());
    $beforeStore = new PostgresReceiptStore(app(DatabaseManager::class), $clock, $before->getName());

    expect($readAfter->isBelow($position))->toBeFalse()
        ->and(visibleIn($after, $position, $snapshotAfter))->toBeTrue()
        ->and($afterStore->find($receipt->changesetId)?->position->value)->toBe($position->value)
        ->and($beforeStore->find($receipt->changesetId))->toBeNull()
        ->and(readPosition($before)[0]->equals($readBefore))->toBeTrue();

    $after->rollBack();
    $before->rollBack();
});

it('gives the receipt and the events of one changeset the same position, its transaction\'s xid8', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-01-01T00:00:01Z'));
    app(PartitionFixtures::class)->coverClock($clock, new DateInterval('P1D'));
    [$writer] = app(IndependentConnections::class)->open(1);
    $store = new PostgresReceiptStore(app(DatabaseManager::class), $clock, $writer->getName());

    $writer->beginTransaction();
    $receipt = ReceiptTables::at($writer, ReceiptTables::receipt('2026-01-01T00:00:00Z'));
    $store->store($receipt);
    $events = new EventWriter(app(DatabaseManager::class), $clock, $writer->getName())->write($receipt->changesetId, EventStream::Interactive, [
        CounterRaised::of('counter-1', 1),
        CounterRaised::of('counter-2', 4),
    ]);
    $writer->commit();

    $stored = $store->find($receipt->changesetId);

    expect($stored?->position->value)->toBe($receipt->position->value)
        ->and(array_map(static fn (EventPosition $event): string => (string) $event->xid, $events))->toBe([$receipt->position->value, $receipt->position->value])
        ->and(ReceiptTables::owner()->scalar('select position::text from receipts where changeset_id = ?', [$receipt->changesetId->toString()]))->toBe($receipt->position->value);

    // The next transaction commits at a higher position.
    $writer->beginTransaction();
    $next = ReceiptTables::position($writer);
    $writer->rollBack();

    expect($receipt->position->isBelow($next))->toBeTrue();
});

it('reads the commit position of the caller\'s transaction, and refuses without one before any statement', function (): void {
    [$connection] = app(IndependentConnections::class)->open(1);
    $position = new TransactionPosition(app(DatabaseManager::class), $connection->getName());

    /** @var list<string> $statements */
    $statements = [];
    $connection->listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    expect(fn (): CommitPosition => $position->current())->toThrow(TransactionRequired::class, 'the connection has none open')
        ->and($statements)->toBe([]);

    $connection->beginTransaction();
    $first = $position->current();
    $again = $position->current();
    $xid = $connection->scalar('select pg_current_xact_id()::text');
    $connection->commit();

    expect($first->value)->toBe($xid)
        ->and($again->equals($first))->toBeTrue()
        ->and($statements)->toBe([TransactionPosition::CURRENT, TransactionPosition::CURRENT, 'select pg_current_xact_id()::text']);
});
