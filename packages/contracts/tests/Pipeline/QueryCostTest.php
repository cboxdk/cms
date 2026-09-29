<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Pipeline;

use Cbox\Cms\Contracts\Pipeline\QueryCost;
use InvalidArgumentException;

/*
 * A query's cost (PRD 6.2, 8.8): a whole number from 0, which exceeds a budget only when it is
 * above it.
 */

it('exceeds a budget only above it', function (): void {
    expect(new QueryCost(4)->exceeds(new QueryCost(3)))->toBeTrue()
        ->and(new QueryCost(3)->exceeds(new QueryCost(3)))->toBeFalse()
        ->and(new QueryCost(0)->exceeds(new QueryCost(0)))->toBeFalse()
        ->and(new QueryCost(2)->exceeds(new QueryCost(3)))->toBeFalse();
});

it('refuses a cost below 0', function (): void {
    expect(fn (): QueryCost => new QueryCost(-1))->toThrow(InvalidArgumentException::class, 'A query cost is a whole number from 0; it is -1.');
});
