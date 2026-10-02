<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Boundary;

use Cbox\Cms\Cli\Boundary\SignalStop;
use Cbox\Cms\Cli\Boundary\SubscriptionArguments;
use Cbox\Cms\Contracts\Subscribers\Lane;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Domain\AggregateKey;
use InvalidArgumentException;

/*
 * The arguments of the cms:events:* commands, and the stop a signal requests.
 */

it('runs the critical lane and refuses the others', function (): void {
    expect(SubscriptionArguments::lane('critical'))->toBe(Lane::Critical)
        ->and(fn (): Lane => SubscriptionArguments::lane('background'))->toThrow(InvalidArgumentException::class, 'the background lane is not built yet')
        ->and(fn (): Lane => SubscriptionArguments::lane(null))->toThrow(InvalidArgumentException::class, '--lane must be one of');
});

it('reads a subscription and an aggregate', function (): void {
    expect(SubscriptionArguments::subscription('fragments.invalidate')->value)->toBe('fragments.invalidate')
        ->and(SubscriptionArguments::aggregate('entry:0196')->toString())->toBe('entry:0196')
        ->and(fn (): SubscriptionName => SubscriptionArguments::subscription(null))->toThrow(InvalidArgumentException::class, 'The subscription name "" must be')
        ->and(fn (): AggregateKey => SubscriptionArguments::aggregate(['entry:1']))->toThrow(InvalidArgumentException::class, 'written <type>:<id>');
});

it('requests a stop once asked to', function (): void {
    $stop = new SignalStop;

    expect($stop->requested())->toBeFalse();

    $stop->request();

    expect($stop->requested())->toBeTrue();
});
