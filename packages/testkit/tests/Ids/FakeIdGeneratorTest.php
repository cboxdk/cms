<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Ids;

use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateInterval;
use DateTimeImmutable;

/*
 * The FakeIdGenerator's own behaviour. The shared contract suite (tests/Contract) checks what every
 * generator promises; these cases check what only the fake promises: the same seed and the same
 * clock movements give the same ids.
 */

/**
 * Ids from a fake with the given seed while its clock moves forwards, stands still and steps back.
 *
 * @return list<string>
 */
function sequenceFor(int $seed): array
{
    $clock = new FakeClock;
    $generator = new FakeIdGenerator($seed, $clock);
    $ids = [];

    for ($i = 0; $i < 1000; $i++) {
        $ids[] = $generator->next()->value;

        match ($i % 4) {
            0 => $clock->advance(new DateInterval('PT1S')),
            1 => null,
            2 => $clock->set($clock->now()->modify('-3 seconds')),
            default => $clock->advance(new DateInterval('PT5S')),
        };
    }

    return $ids;
}

it('gives an identical sequence for the same seed', function (int $seed): void {
    expect(sequenceFor($seed))->toBe(sequenceFor($seed));
})->with([0, 1, 42, PHP_INT_MAX, PHP_INT_MIN]);

it('gives a different sequence for another seed', function (): void {
    $first = sequenceFor(1);
    $second = sequenceFor(2);

    expect(array_intersect($first, $second))->toBe([]);
});

it('uses seed 0 and a new FakeClock by default', function (): void {
    $default = new FakeIdGenerator;
    $explicit = new FakeIdGenerator(FakeIdGenerator::DEFAULT_SEED, new FakeClock);

    $id = $default->next();

    expect($default->seed)->toBe(0)
        ->and($id->value)->toBe($explicit->next()->value)
        ->and($id->unixMilliseconds())->toBe(Uuid7::unixMillisecondsOf(new DateTimeImmutable(FakeClock::START)));
});

it('does not share state between instances', function (): void {
    $first = new FakeIdGenerator(9);
    $first->next();
    $first->next();

    $second = new FakeIdGenerator(9);
    $fresh = new FakeIdGenerator(9);

    expect($second->next()->value)->toBe($fresh->next()->value);
});
