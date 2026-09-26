<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\ReceiptStore\Adapter\ReceiptRows;
use Cbox\Cms\Core\ReceiptStore\Adapter\UnreadableReceiptRow;
use DateTimeImmutable;

/*
 * The row mapper of the Postgres receipt store: rows as Postgres returns them become a Receipt,
 * and a row the store would never write is an UnreadableReceiptRow that names the column.
 */

const RECEIPT_ID = '019b7658-3000-7000-8000-000000000000';

/**
 * @param  array<string, mixed>  $overrides
 */
function receiptRow(array $overrides = []): object
{
    return (object) [...[
        'changeset_id' => RECEIPT_ID,
        'retention_class' => 'standard',
        'outcome' => 'committed',
        'wait_level' => 'origin',
    ], ...$overrides];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function projectionRow(array $overrides = []): object
{
    return (object) [...[
        'projection' => 'edge',
        'state' => 'pending',
        'acknowledged_at' => null,
    ], ...$overrides];
}

it('maps a receipt row and its projection rows, with the acknowledgement in UTC to the microsecond', function (): void {
    $receipt = ReceiptRows::receipt(receiptRow(['outcome' => 'committed_wait_timeout', 'retention_class' => 'evidence']), [
        projectionRow(['projection' => 'search', 'state' => 'acknowledged', 'acknowledged_at' => '2026-01-01 01:00:02.123456+01']),
        projectionRow(),
    ]);

    expect($receipt)->toEqual(new Receipt(
        Outcome::CommittedWaitTimeout,
        ChangesetId::fromString(RECEIPT_ID),
        WaitLevel::Origin,
        RetentionClass::Evidence,
        [
            ProjectionStatus::pending(new ProjectionName('edge')),
            ProjectionStatus::acknowledged(new ProjectionName('search'), new DateTimeImmutable('2026-01-01T00:00:02.123456Z')),
        ],
    ))->and($receipt->projections[1]->acknowledgedAt?->format('Y-m-d\TH:i:s.u e'))->toBe('2026-01-01T00:00:02.123456 UTC');
});

it('reads a whole second without a fraction, as Postgres prints it', function (): void {
    $receipt = ReceiptRows::receipt(receiptRow(), [projectionRow(['state' => 'acknowledged', 'acknowledged_at' => '2026-01-01 00:00:02+00'])]);

    expect($receipt->projections[0]->acknowledgedAt?->format('Y-m-d\TH:i:s.uP'))->toBe('2026-01-01T00:00:02.000000+00:00');
});

it('refuses a row the store never writes, naming the column', function (object $receipt, array $projections, string $message): void {
    expect(static fn (): Receipt => ReceiptRows::receipt($receipt, $projections))->toThrow(UnreadableReceiptRow::class, $message);
})->with([
    'unknown outcome' => [receiptRow(['outcome' => 'shipped']), [], 'outcome holds "shipped"'],
    'rejected outcome' => [receiptRow(['outcome' => 'rejected']), [], 'does not make a valid receipt'],
    'unknown wait level' => [receiptRow(['wait_level' => 'soon']), [], 'wait_level holds "soon"'],
    'unknown retention class' => [receiptRow(['retention_class' => 'forever']), [], 'retention_class holds "forever"'],
    'malformed changeset id' => [receiptRow(['changeset_id' => 'not-a-uuid']), [], 'does not make a valid receipt'],
    'missing column' => [(object) ['changeset_id' => RECEIPT_ID], [], 'has no column outcome'],
    'number instead of text' => [receiptRow(['outcome' => 1]), [], 'receipts.outcome is int'],
    'null outcome' => [receiptRow(['outcome' => null]), [], 'receipts.outcome is null'],
    'projection row not an object' => [receiptRow(), [['projection' => 'edge']], 'got array'],
    'unknown state' => [receiptRow(), [projectionRow(['state' => 'done'])], 'state holds "done"'],
    'acknowledged without a time' => [receiptRow(), [projectionRow(['state' => 'acknowledged'])], 'does not make a valid receipt'],
    'malformed time' => [receiptRow(), [projectionRow(['state' => 'acknowledged', 'acknowledged_at' => 'yesterday-ish'])], 'acknowledged_at holds "yesterday-ish"'],
    'bad projection name' => [receiptRow(), [projectionRow(['projection' => 'Edge'])], 'does not make a valid receipt'],
    'projection listed twice' => [receiptRow(), [projectionRow(), projectionRow()], 'does not make a valid receipt'],
]);
