<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Ids;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Core\Bindings\Boundary\InvalidContractBinding;
use Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use stdClass;

/*
 * The real UUIDv7 generator and its binding in the container. The shared contract suite runs
 * against it in tests/Contract.
 */

it('uses the system time through the bound clock', function (): void {
    $before = (int) floor(microtime(true) * 1000);
    $id = app(IdGenerator::class)->next();
    $after = (int) floor(microtime(true) * 1000);

    expect($id->unixMilliseconds())->toBeGreaterThanOrEqual($before)
        ->and($id->unixMilliseconds())->toBeLessThanOrEqual($after);
});

it('draws fresh random bits in every instance', function (): void {
    $clock = new FakeClock;
    $values = [];

    for ($i = 0; $i < 50; $i++) {
        $values[] = new SystemIdGenerator($clock)->next()->value;
    }

    expect(array_unique($values))->toHaveCount(50);
});

it('resolves the UUIDv7 generator from the container, once', function (): void {
    expect(app(IdGenerator::class))->toBeInstanceOf(SystemIdGenerator::class)
        ->and(app(IdGenerator::class))->toBe(app(IdGenerator::class))
        ->and(config('cbox-cms.contracts.'.IdGenerator::class))->toBe(SystemIdGenerator::class);
});

it('reads the time from the clock bound in the container', function (): void {
    $clock = new FakeClock(new DateTimeImmutable('2031-07-14T12:00:00.456789+00:00'));
    app()->instance(Clock::class, $clock);

    expect(app(IdGenerator::class)->next()->unixMilliseconds())->toBe((int) $clock->now()->format('Uv'));
});

it('resolves the generator that the configuration names', function (): void {
    config()->set('cbox-cms.contracts.'.IdGenerator::class, FakeIdGenerator::class);

    expect(app(IdGenerator::class))->toBeInstanceOf(FakeIdGenerator::class);
});

it('refuses a configured class that is not an id generator', function (): void {
    config()->set('cbox-cms.contracts.'.IdGenerator::class, stdClass::class);

    expect(fn (): IdGenerator => app(IdGenerator::class))->toThrow(InvalidContractBinding::class, 'does not implement');
});
