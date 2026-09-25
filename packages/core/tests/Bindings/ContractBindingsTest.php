<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Bindings;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Core\Bindings\Boundary\ContractBindings;
use Cbox\Cms\Core\Bindings\Boundary\InvalidContractBinding;
use Cbox\Cms\Core\Clock\Adapter\SystemClock;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use stdClass;

/*
 * Reading a contract's implementation from `cms.contracts`. A wrong entry is a deploy error with
 * a message that names the key.
 */

abstract class AbstractClock implements Clock {}

function bindingsWith(mixed $implementation): ContractBindings
{
    return new ContractBindings(new Repository(['cms' => ['contracts' => [Clock::class => $implementation]]]));
}

it('returns the configured implementation', function (): void {
    expect(bindingsWith(SystemClock::class)->implementationOf(Clock::class))->toBe(SystemClock::class);
});

it('refuses a missing entry and names the key', function (): void {
    $bindings = new ContractBindings(new Repository(['cms' => ['contracts' => []]]));

    expect(fn (): string => $bindings->implementationOf(Clock::class))
        ->toThrow(InvalidContractBinding::class, 'No implementation of ['.Clock::class.'] is configured. Set [cms.contracts.'.Clock::class.']');
});

it('refuses an entry that is not a class implementing the contract', function (mixed $implementation, string $message): void {
    expect(fn (): string => bindingsWith($implementation)->implementationOf(Clock::class))
        ->toThrow(InvalidContractBinding::class, $message);
})->with([
    'null' => [null, 'No implementation of'],
    'an empty string' => ['', 'No implementation of'],
    'a number' => [42, 'No implementation of'],
    'an unknown class' => ['Acme\\Missing\\Clock', 'is not a class'],
    'the contract itself' => [Clock::class, 'is not a class'],
    'a class that is not a clock' => [stdClass::class, 'does not implement'],
    'an abstract clock' => [AbstractClock::class, 'cannot be instantiated'],
]);

it('builds the implementation from the container', function (): void {
    $clock = bindingsWith(SystemClock::class)->resolve(new Container, Clock::class);

    expect($clock)->toBeInstanceOf(SystemClock::class);
});

it('refuses what the container builds when it is not the contract', function (): void {
    $container = new Container;
    $container->bind(SystemClock::class, static fn (): stdClass => new stdClass);

    expect(fn (): Clock => bindingsWith(SystemClock::class)->resolve($container, Clock::class))
        ->toThrow(InvalidContractBinding::class, 'The container built [stdClass] for ['.Clock::class.'], which does not implement it.');
});
