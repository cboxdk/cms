<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\ReceiptStore;

use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use DateTimeImmutable;

/*
 * The Clock-based window of the Postgres store: the lowest changeset id whose Standard receipt is
 * live at a time. It must agree with RetentionClass::expiresAt(), which the fake uses, to the
 * microsecond.
 */

it('agrees with RetentionClass::expiresAt() on both sides of the expiry instant', function (string $changesetTime, string $now, bool $live): void {
    $id = new ChangesetId(Uuid7::lowestAt(Uuid7::unixMillisecondsOf(new DateTimeImmutable($changesetTime))));
    $at = new DateTimeImmutable($now);
    $lowest = PostgresReceiptStore::lowestLiveStandard($at);

    expect($id->value->compareTo($lowest) >= 0)->toBe($live)
        ->and($at <= RetentionClass::Standard->expiresAt($id))->toBe($live);
})->with([
    'a day before' => ['2026-01-01T00:00:00.250Z', '2026-01-07T00:00:00.250000Z', true],
    'at expiry' => ['2026-01-01T00:00:00.250Z', '2026-01-08T00:00:00.250000Z', true],
    'a microsecond after' => ['2026-01-01T00:00:00.250Z', '2026-01-08T00:00:00.250001Z', false],
    'a microsecond before' => ['2026-01-01T00:00:00.250Z', '2026-01-08T00:00:00.249999Z', true],
    'a millisecond after' => ['2026-01-01T00:00:00.250Z', '2026-01-08T00:00:00.251000Z', false],
    'at the epoch' => ['1970-01-01T00:00:00Z', '1970-01-03T00:00:00Z', true],
]);

it('is the lowest id at the start of the live window', function (): void {
    expect(PostgresReceiptStore::lowestLiveStandard(new DateTimeImmutable('2026-01-08T00:00:00.250001Z'))->value)
        ->toBe(Uuid7::lowestAt(Uuid7::unixMillisecondsOf(new DateTimeImmutable('2026-01-01T00:00:00.251Z')))->value)
        ->and(PostgresReceiptStore::lowestLiveStandard(new DateTimeImmutable('1970-01-02T00:00:00Z'))->value)
        ->toBe(Uuid7::lowestAt(0)->value);
});
