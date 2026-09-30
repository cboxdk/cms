<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Scale;

use Cbox\Cms\Tooling\Scale\Boundary\ExplainJson;
use Cbox\Cms\Tooling\Scale\Boundary\MachineDescription;
use Cbox\Cms\Tooling\Scale\Boundary\ScaleArguments;
use Cbox\Cms\Tooling\Scale\Domain\ListingTimes;
use Cbox\Cms\Tooling\Scale\Domain\ScaleOptions;
use InvalidArgumentException;
use UnexpectedValueException;

/*
 * composer scale:check (tools/bin/scale-check.php, GUARDRAILS 4.3, MILESTONES M1): its arguments,
 * the database it may use, the execution time it reads from EXPLAIN and the median it judges.
 */

it('defaults to a million entries of the scale profile in cms_scale, and reads each argument', function (): void {
    $defaults = ScaleArguments::parse([], 'cms', 'cms_test');
    $given = ScaleArguments::parse(['--entries=2_000', '--runs=5', '--profile=small', '--seed=3', '--sections=7', '--database=cms_scale_2', '--keep'], 'cms', 'cms_test');

    expect([$defaults->entries, $defaults->runs, $defaults->profile, $defaults->seed, $defaults->database, $defaults->sections, $defaults->keep])
        ->toBe([1_000_000, 11, 'scale', 1, 'cms_scale', 40, false])
        ->and([$given->entries, $given->runs, $given->profile, $given->seed, $given->database, $given->sections, $given->keep])
        ->toBe([2_000, 5, 'small', 3, 'cms_scale_2', 7, true]);
});

it('never uses the shared dev database or a test database', function (string $database): void {
    expect(static fn (): ScaleOptions => ScaleArguments::parse(['--database='.$database], 'cms', 'cms_test'))
        ->toThrow(InvalidArgumentException::class, 'never the shared dev database "cms" or a test database "cms_test"');
})->with(['cms', 'cms_test', 'cms_test_b089e836177d']);

it('refuses unknown, repeated and out of range arguments', function (array $arguments, string $message): void {
    expect(static fn (): ScaleOptions => ScaleArguments::parse(array_values(array_filter($arguments, is_string(...))), 'cms', 'cms_test'))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown' => [['--rows=5'], 'Unknown or repeated argument "--rows=5"'],
    'repeated' => [['--runs=5', '--runs=6'], 'Unknown or repeated argument "--runs=6"'],
    'not whole' => [['--entries=1e6'], '--entries is a whole number'],
    'no runs' => [['--runs=0'], '--runs 1 to 1000'],
    'bad name' => [['--database=Scale'], 'lower-case letters'],
]);

it('reads the execution time from EXPLAIN (ANALYZE, FORMAT JSON)', function (): void {
    expect(ExplainJson::executionMilliseconds('[{"Plan": {"Node Type": "Limit"}, "Planning Time": 0.2, "Execution Time": 1.625}]'))->toBe(1.625)
        ->and(ExplainJson::executionMilliseconds('[{"Execution Time": 3}]'))->toBe(3.0)
        ->and(static fn (): float => ExplainJson::executionMilliseconds('[{"Plan": {}}]'))->toThrow(UnexpectedValueException::class, 'no "Execution Time"')
        ->and(static fn (): float => ExplainJson::executionMilliseconds('not json'))->toThrow(UnexpectedValueException::class, 'no JSON');
});

it('judges the median of the runs against the budget', function (): void {
    $odd = new ListingTimes('page', [9.0, 25.0, 3.0]);
    $even = new ListingTimes('page', [30.0, 10.0, 21.0, 19.0]);

    expect($odd->median())->toBe(9.0)
        ->and($odd->within(ScaleOptions::BUDGET_MS))->toBeTrue()
        ->and($even->median())->toBe(20.0)
        ->and($even->within(20.0))->toBeTrue()
        ->and(new ListingTimes('page', [20.5])->within(20.0))->toBeFalse()
        ->and($odd->line(20.0))->toBe('page: median 9.000 ms over 3 runs (min 3.000, max 25.000), budget 20.0 ms: pass')
        ->and(new ListingTimes('page', [21.0])->line(20.0))->toEndWith('fail');
});

it('describes the machine it runs on', function (): void {
    $lines = MachineDescription::lines();

    expect($lines[0])->toStartWith('Machine: ')->toContain(' cores, ')->toContain(' memory, load ');
});
