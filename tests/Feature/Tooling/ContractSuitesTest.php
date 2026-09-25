<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Core\Tests\Contract\SystemClockContractTest;
use Cbox\Cms\Testkit\Clock\ClockContract;
use Cbox\Cms\Testkit\Tests\Contract\FakeClockContractTest;
use Cbox\Cms\Tests\Support\Phpstan;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Process\Process;

/*
 * GUARDRAILS 2.3 and 9: the fake and the real adapter of a contract run the same shared suite.
 * These tests read what Pest will run in the Contract suite, so a shared case that one
 * implementation skips, or a contract test class that is moved out of the suite, fails here.
 */

/**
 * The test ids (Class::method) that Pest lists for the Contract suite with the given filter.
 *
 * @return list<string>
 */
function contractTests(string $filter): array
{
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/pest', '--list-tests', '--colors=never', '--testsuite=Contract', '--filter='.$filter],
        Phpstan::root(),
        null,
        null,
        120,
    );
    $process->mustRun();

    preg_match_all('/^ - (\S+?::\w+)/m', $process->getOutput(), $matches);

    return $matches[1];
}

/**
 * The names of the test methods a shared suite trait declares.
 *
 * @param  class-string  $trait
 * @return list<string>
 */
function sharedCases(string $trait): array
{
    $methods = array_filter(
        new ReflectionClass($trait)->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => $method->getAttributes(Test::class) !== [],
    );

    return array_values(array_map(static fn (ReflectionMethod $method): string => $method->getName(), $methods));
}

it('runs every shared Clock case once for the SystemClock and once for the FakeClock', function (): void {
    $cases = sharedCases(ClockContract::class);

    $expected = [];

    foreach ([SystemClockContractTest::class, FakeClockContractTest::class] as $class) {
        foreach ($cases as $case) {
            $expected[] = $class.'::'.$case;
        }
    }

    $listed = contractTests('Clock');
    sort($expected);
    sort($listed);

    expect($cases)->toContain('now_is_in_the_utc_time_zone', 'now_is_an_immutable_value', 'now_keeps_microseconds')
        ->and($listed)->toBe($expected);
});
