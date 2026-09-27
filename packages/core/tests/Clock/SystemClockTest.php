<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Clock;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Core\Bindings\Boundary\InvalidContractBinding;
use Cbox\Cms\Core\Clock\Adapter\SystemClock;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;
use DateTimeZone;
use stdClass;

/*
 * The system clock and its binding in the container. The shared contract suite runs against it in
 * tests/Contract.
 */

it('reads the system time', function (): void {
    $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $now = new SystemClock()->now();
    $after = new DateTimeImmutable('now', new DateTimeZone('UTC'));

    expect($now >= $before && $now <= $after)->toBeTrue();
});

it('reads the clock again on every call', function (): void {
    $clock = new SystemClock;

    $first = $clock->now();
    usleep(2000);

    expect($clock->now())->not->toEqual($first);
});

it('resolves the system clock from the container, once', function (): void {
    expect(app(Clock::class))->toBeInstanceOf(SystemClock::class)
        ->and(app(Clock::class))->toBe(app(Clock::class))
        ->and(config('cbox-cms.contracts.'.Clock::class))->toBe(SystemClock::class);
});

it('resolves the clock that the configuration names', function (): void {
    config()->set('cbox-cms.contracts.'.Clock::class, FakeClock::class);

    $clock = app(Clock::class);

    expect($clock)->toBeInstanceOf(FakeClock::class)
        ->and($clock->now()->format('Y-m-d\TH:i:s.uP'))->toBe(FakeClock::START);
});

it('lets the application configuration win over the package default', function (): void {
    config()->set('cbox-cms', ['contracts' => [Clock::class => FakeClock::class]]);

    new CoreServiceProvider(app())->register();

    expect(config('cbox-cms.contracts.'.Clock::class))->toBe(FakeClock::class)
        ->and(app(Clock::class))->toBeInstanceOf(FakeClock::class);
});

it('keeps the default clock and id generator when the application configures only other contracts', function (): void {
    config()->set('cbox-cms', ['contracts' => ['Acme\\Contracts\\Other' => 'Acme\\Other']]);

    new CoreServiceProvider(app())->register();

    expect(config('cbox-cms.contracts'))->toBe([
        Clock::class => SystemClock::class,
        IdGenerator::class => SystemIdGenerator::class,
        ReceiptStore::class => PostgresReceiptStore::class,
        IdempotencyStore::class => PostgresIdempotencyStore::class,
        'Acme\\Contracts\\Other' => 'Acme\\Other',
    ])->and(app(Clock::class))->toBeInstanceOf(SystemClock::class)
        ->and(app(IdGenerator::class))->toBeInstanceOf(SystemIdGenerator::class);
});

it('refuses a configured class that is not a clock', function (): void {
    config()->set('cbox-cms.contracts.'.Clock::class, stdClass::class);

    expect(fn (): Clock => app(Clock::class))->toThrow(InvalidContractBinding::class, 'does not implement');
});
