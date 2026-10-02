<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\TypeTables;

use Cbox\Cms\Contracts\TypeTables\FilterOperator;
use Cbox\Cms\Contracts\TypeTables\InvalidTypeTableQuery;

/*
 * The messages of InvalidTypeTableQuery say what each operator takes, and show a refused column
 * name in full up to 64 bytes and cut after that.
 */

it('says how many values each operator takes', function (FilterOperator $operator, string $takes): void {
    expect(InvalidTypeTableQuery::valueCount($operator, 3)->getMessage())->toBe("The filter operator {$operator->value} takes {$takes}, got 3.");
})->with([
    'eq' => [FilterOperator::Eq, 'exactly one value'],
    'gte' => [FilterOperator::Gte, 'exactly one value'],
    'in' => [FilterOperator::In, 'one or more values'],
    'nin' => [FilterOperator::NotIn, 'one or more values'],
    'null' => [FilterOperator::IsNull, 'no value'],
    'not_null' => [FilterOperator::IsNotNull, 'no value'],
]);

it('shows a refused column of 64 bytes in full and cuts a longer one after 64', function (): void {
    $full = str_repeat('A', 64);

    expect(InvalidTypeTableQuery::column($full)->getMessage())->toEndWith("got \"{$full}\".")
        ->and(InvalidTypeTableQuery::column($full.'B')->getMessage())->toEndWith("got \"{$full}...\".");
});
