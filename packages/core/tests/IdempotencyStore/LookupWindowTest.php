<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\IdempotencyStore;

use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use DateTimeImmutable;

/*
 * The Postgres store's lookup window: from 7 days before the Clock's time to the end of its UTC
 * day. Every record still live at that time lies inside it, and it spans 8 daily partitions.
 */

it('runs from 7 days before now to the end of now\'s UTC day', function (string $now, string $from, string $until): void {
    [$start, $end] = PostgresIdempotencyStore::window(new DateTimeImmutable($now));

    expect($start->format('Y-m-d\TH:i:s.uP'))->toBe($from)
        ->and($end->format('Y-m-d\TH:i:s.uP'))->toBe($until);
})->with([
    'midday' => ['2026-03-06T12:00:00.123456Z', '2026-02-27T12:00:00.123456+00:00', '2026-03-07T00:00:00.000000+00:00'],
    'midnight' => ['2026-03-06T00:00:00Z', '2026-02-27T00:00:00.000000+00:00', '2026-03-07T00:00:00.000000+00:00'],
    'another time zone' => ['2026-03-06T01:00:00+02:00', '2026-02-26T23:00:00.000000+00:00', '2026-03-06T00:00:00.000000+00:00'],
    'a leap day' => ['2028-03-01T08:00:00Z', '2028-02-23T08:00:00.000000+00:00', '2028-03-02T00:00:00.000000+00:00'],
]);

it('holds every record still live at now: a changeset at most 7 days old, created at or after the changeset', function (string $changesetTime, string $now): void {
    $id = new ChangesetId(Uuid7::lowestAt(Uuid7::unixMillisecondsOf(new DateTimeImmutable($changesetTime))));
    $at = new DateTimeImmutable($now);
    [$start, $end] = PostgresIdempotencyStore::window($at);
    $createdAt = new DateTimeImmutable($changesetTime);

    expect($at <= RetentionClass::Standard->expiresAt($id))->toBeTrue()
        ->and($createdAt >= $start)->toBeTrue()
        ->and($createdAt < $end)->toBeTrue();
})->with([
    'at expiry' => ['2026-01-01T00:00:00.250Z', '2026-01-08T00:00:00.250000Z'],
    'a microsecond before expiry' => ['2026-01-01T23:59:59.999Z', '2026-01-08T23:59:59.998999Z'],
    'just after midnight' => ['2026-01-01T23:59:59.900Z', '2026-01-02T00:00:00.100000Z'],
    'the same moment' => ['2026-01-01T12:00:00Z', '2026-01-01T12:00:00Z'],
]);

it('spans 8 daily partitions', function (string $now): void {
    [$start, $end] = PostgresIdempotencyStore::window(new DateTimeImmutable($now));
    $days = 0;

    for ($day = $start->setTime(0, 0); $day < $end; $day = $day->modify('+1 day')) {
        $days++;
    }

    expect($days)->toBe(8);
})->with(['2026-03-06T12:00:00Z', '2026-03-06T00:00:00Z', '2026-03-06T23:59:59.999999Z']);
