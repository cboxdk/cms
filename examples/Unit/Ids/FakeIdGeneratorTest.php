<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;

// The FakeIdGenerator makes ids the way the real generator does, from a seeded random engine and
// a clock, so a test gets the same ids on every run.

it('gives the same ids for the same seed and the same clock', function (): void {
    $first = new FakeIdGenerator(seed: 7);
    $second = new FakeIdGenerator(seed: 7);
    $otherSeed = new FakeIdGenerator(seed: 8);

    $ids = [$first->next()->value, $first->next()->value];

    expect($ids)->toBe([$second->next()->value, $second->next()->value])
        ->and($otherSeed->next()->value)->not->toBe($ids[0]);
});

it('puts the milliseconds of the clock in the first 48 bits', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2026-03-01T12:00:00.250999+00:00'));

    $id = new FakeIdGenerator(clock: $clock)->next();

    expect($id->unixMilliseconds())->toBe(1_772_366_400_250)
        ->and(Uuid7::unixMillisecondsOf($clock->now()))->toBe(1_772_366_400_250)
        ->and(substr(str_replace('-', '', $id->value), 0, 12))->toBe(sprintf('%012x', 1_772_366_400_250));
});

it('keeps ids increasing when the clock steps back', function (): void {
    $clock = new FakeClock;
    $ids = new FakeIdGenerator(clock: $clock);

    $before = $ids->next();
    $clock->set($clock->now()->modify('-1 minute'));
    $after = $ids->next();

    expect($after->compareTo($before))->toBeGreaterThan(0)
        ->and($after->unixMilliseconds())->toBe($before->unixMilliseconds());
});

it('bounds the ids of one day with lowestAt() and highestAt()', function (): void {
    $day = new DateTimeImmutable('2026-03-01T00:00:00+00:00');
    $nextDay = $day->modify('+1 day');
    $from = Uuid7::lowestAt(Uuid7::unixMillisecondsOf($day));
    $until = Uuid7::lowestAt(Uuid7::unixMillisecondsOf($nextDay));
    $last = Uuid7::highestAt(Uuid7::unixMillisecondsOf($nextDay) - 1);
    $clock = new FakeClock($day);
    $ids = new FakeIdGenerator(clock: $clock);

    foreach (['00:00:00.000000', '12:00:00.000000', '23:59:59.999999'] as $time) {
        $clock->set(new DateTimeImmutable("2026-03-01T{$time}+00:00"));
        $id = $ids->next();

        expect($id->compareTo($from))->toBeGreaterThanOrEqual(0, "At {$time}.")
            ->and($id->compareTo($last))->toBeLessThanOrEqual(0, "At {$time}.")
            ->and($id->compareTo($until))->toBeLessThan(0, "At {$time}.");
    }

    $clock->set($nextDay);

    expect($ids->next()->compareTo($until))->toBeGreaterThanOrEqual(0);
});

it('is the IdGenerator of every class the container builds once it is bound', function (): void {
    app()->instance(IdGenerator::class, new FakeIdGenerator(seed: 7));

    expect(app(IdGenerator::class)->next()->value)->toBe(new FakeIdGenerator(seed: 7)->next()->value);
});
