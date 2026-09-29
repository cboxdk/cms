<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads;

use Cbox\Cms\Core\Reads\Boundary\QueryConfig;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Illuminate\Config\Repository;
use InvalidArgumentException;

/*
 * The query pipeline's settings from cbox-cms.queries: the budgets of the anonymous principal and
 * of an actor, each a whole number from 0, with defaults when they are not set.
 */

it('reads the budgets, and uses the defaults for what is not set', function (): void {
    $set = QueryConfig::read(new Repository(['cbox-cms' => ['queries' => ['budgets' => ['anonymous' => 0, 'actor' => 7]]]]));
    $defaults = QueryConfig::read(new Repository([]));

    expect([$set->anonymousBudget->units, $set->actorBudget->units])->toBe([0, 7])
        ->and([$defaults->anonymousBudget->units, $defaults->actorBudget->units])->toBe([QueryConfig::DEFAULT_ANONYMOUS_BUDGET, QueryConfig::DEFAULT_ACTOR_BUDGET])
        ->and([QueryConfig::DEFAULT_ANONYMOUS_BUDGET, QueryConfig::DEFAULT_ACTOR_BUDGET])->toBe([200, 1000]);
});

it('refuses a budget that is not a whole number from 0', function (mixed $value, string $shown): void {
    expect(fn (): QuerySettings => QueryConfig::read(new Repository(['cbox-cms' => ['queries' => ['budgets' => ['actor' => $value]]]])))
        ->toThrow(InvalidArgumentException::class, 'The setting cbox-cms.queries.budgets.actor must be a whole number from 0; it is '.$shown.'.');
})->with([
    'negative' => [-1, '-1'],
    'text' => ['100', 'string'],
    'fraction' => [1.5, 'float'],
]);

it('matches the defaults of the package configuration', function (): void {
    $config = require dirname(__DIR__, 2).'/config/cbox-cms.php';

    expect(is_array($config) ? $config['queries'] ?? null : null)->toBe(['budgets' => ['anonymous' => QueryConfig::DEFAULT_ANONYMOUS_BUDGET, 'actor' => QueryConfig::DEFAULT_ACTOR_BUDGET]]);
});
