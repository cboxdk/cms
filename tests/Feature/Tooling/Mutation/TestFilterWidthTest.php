<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Mutation;

use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Mutation\Adapter\CoverageFilterWidener;
use Cbox\Cms\Tooling\Mutation\Domain\TestFilterWidth;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;
use SebastianBergmann\CodeCoverage\Serialization\Serializer;
use SebastianBergmann\CodeCoverage\Serialization\Unserializer;

/*
 * pest-plugin-mutate names every test that covers a mutation in one --filter argument, which
 * Linux refuses past 128 KiB ("Argument list too long"), as it did for the service providers that
 * every test boots (M1-T6). TestFilterWidth gives the lines of a file whose covering tests would
 * pass that the test classes instead, and CoverageFilterWidener rewrites Pest's coverage file
 * with it before the mutations are made. tests/Mutation runs it for real.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * $count Pest test ids of the class `P\Tests\Unit\<class>`, each with a description of about
 * 160 characters.
 *
 * @return list<non-empty-string>
 */
function longTestIds(string $class, int $count): array
{
    return array_map(
        static fn (int $number): string => sprintf('P\Tests\Unit\%s::__pest_evaluable_it_does_thing_%04d_%s', $class, $number, str_repeat('with_a_long_description_', 6)),
        range(1, $count),
    );
}

it('makes the filter entry of a test id as pest-plugin-mutate does', function (): void {
    expect(TestFilterWidth::entry('P\Tests\Unit\FooTest::__pest_evaluable_it_names__the_thing'))->toBe('FooTest::(.*)it.names.{1,2}the.thing')
        ->and(TestFilterWidth::entry('Cbox\Cms\Core\Tests\Contract\ClockTest::testItTicks#with data'))->toBe('ClockTest::(.*)testItTicks')
        ->and(TestFilterWidth::entry('P\Tests\Unit\FooTest::'))->toBe('FooTest::(.*)')
        ->and(TestFilterWidth::entry('NoNamespaceTest::testIt'))->toBeNull()
        ->and(TestFilterWidth::classOf('P\Tests\Unit\FooTest::__pest_evaluable_it_runs'))->toBe('P\Tests\Unit\FooTest::')
        ->and(TestFilterWidth::classOf('tests/one.phpt'))->toBe('tests/one.phpt');
});

it('counts the bytes of the argument with its quotes, its NUL and each entry once', function (): void {
    expect(TestFilterWidth::argumentBytes([]))->toBe(strlen('--filter=""') + 1)
        ->and(TestFilterWidth::argumentBytes(['P\Tests\Unit\FooTest::testA', 'P\Tests\Unit\FooTest::testA#1', 'P\Tests\Unit\BarTest::testB', 'NoNamespace']))
        ->toBe(strlen('--filter="FooTest::(.*)testA|BarTest::(.*)testB"') + 1);
});

it('leaves every file alone whose covering tests fit one argument', function (): void {
    $testIds = [0 => 'P\Tests\Unit\FooTest::__pest_evaluable_it_a', 1 => 'P\Tests\Unit\BarTest::__pest_evaluable_it_b'];
    $lines = ['/src/Foo.php' => [3 => [0 => 1, 1 => 2], 4 => null, 5 => []]];

    $widened = TestFilterWidth::widen($lines, $testIds);

    expect($widened->widenedFiles)->toBe([])
        ->and($widened->lineCoverage)->toBe($lines)
        ->and($widened->testIds)->toBe($testIds);
});

it('gives each line of a file whose covering tests are too many for one argument their test classes, and keeps the other files', function (): void {
    $wide = [...longTestIds('WideTest', 500), ...longTestIds('OtherTest', 500)];
    $testIds = [...$wide, 'P\Tests\Unit\NarrowTest::__pest_evaluable_it_c', 'tests/one.phpt'];
    $narrow = count($wide);
    $phpt = $narrow + 1;
    $everyTest = array_fill_keys(array_keys($wide), 1);

    expect(TestFilterWidth::argumentBytes($wide))->toBeGreaterThan(TestFilterWidth::ARGUMENT_BYTES);

    $lines = [
        '/src/Provider.php' => [10 => $everyTest, 11 => [0 => 3, $phpt => 1], 12 => null, 13 => []],
        '/src/Narrow.php' => [7 => [$narrow => 1, 0 => 1]],
    ];

    $widened = TestFilterWidth::widen($lines, $testIds);
    $wideClass = array_search('P\Tests\Unit\WideTest::', $widened->testIds, true);
    $otherClass = array_search('P\Tests\Unit\OtherTest::', $widened->testIds, true);

    expect($widened->widenedFiles)->toBe(['/src/Provider.php'])
        ->and($wideClass)->toBe($phpt + 1)
        ->and($otherClass)->toBe($phpt + 2)
        ->and(array_slice($widened->testIds, 0, $phpt + 1, true))->toBe($testIds)
        ->and($widened->lineCoverage['/src/Provider.php'])->toBe([10 => [$wideClass => 1, $otherClass => 1], 11 => [$wideClass => 1, $phpt => 1], 12 => null, 13 => []])
        ->and($widened->lineCoverage['/src/Narrow.php'])->toBe($lines['/src/Narrow.php'])
        ->and(TestFilterWidth::argumentBytes(['P\Tests\Unit\WideTest::', 'P\Tests\Unit\OtherTest::']))->toBe(strlen('--filter="WideTest::(.*)|OtherTest::(.*)"') + 1);
});

it('widens a file whose lines each fit one argument but not all its covering tests together', function (): void {
    $first = longTestIds('FirstTest', 450);
    $second = longTestIds('SecondTest', 450);
    $testIds = [...$first, ...$second];

    expect(TestFilterWidth::argumentBytes($first))->toBeLessThan(TestFilterWidth::ARGUMENT_BYTES)
        ->and(TestFilterWidth::argumentBytes($testIds))->toBeGreaterThan(TestFilterWidth::ARGUMENT_BYTES);

    $widened = TestFilterWidth::widen(['/src/Two.php' => [
        4 => array_fill_keys(range(0, 449), 1),
        5 => array_fill_keys(range(450, 899), 1),
    ]], $testIds);

    expect($widened->widenedFiles)->toBe(['/src/Two.php'])
        ->and($widened->lineCoverage['/src/Two.php'])->toBe([4 => [900 => 1], 5 => [901 => 1]]);
});

/**
 * Writes a coverage file in php-code-coverage's serialization format with the given lines and
 * test ids, as Pest's --coverage-php does, and returns its path.
 *
 * @param  array<non-empty-string, array<int<1, max>, array<int<0, max>, int<1, max>>|null>>  $lines
 * @param  array<int<0, max>, non-empty-string>  $testIds
 * @return non-empty-string
 */
function coverageFile(array $lines, array $testIds): string
{
    $coverage = new ProcessedCodeCoverageData;
    $coverage->setTestIds($testIds);
    $coverage->setLineCoverage($lines);
    $data = [
        'buildInformation' => [
            'timestamp' => 'Tue Sep 29 12:00:00 UTC 2026',
            'runtime' => ['name' => 'PHP', 'version' => PHP_VERSION, 'vendorUrl' => 'https://www.php.net/'],
            'phpCodeCoverage' => ['version' => '14', 'serializationFormat' => Serializer::SERIALIZATION_FORMAT, 'driverInformation' => ['name' => 'PCOV', 'version' => '1']],
        ],
        'basePath' => '/srv/checkout',
        'codeCoverage' => $coverage,
        'testResults' => [],
    ];

    $path = ScratchDirectory::make().'/coverage.php';
    ScratchDirectory::write($path, '<?php // phpunit/php-code-coverage serialization format '.Serializer::SERIALIZATION_FORMAT.PHP_EOL
        ."return \\unserialize(<<<'END_OF_COVERAGE_SERIALIZATION'".PHP_EOL
        .serialize($data).PHP_EOL
        .'END_OF_COVERAGE_SERIALIZATION'.PHP_EOL
        .');');

    return $path;
}

it('rewrites the coverage file with the widened lines, which pest-plugin-mutate then reads', function (): void {
    $testIds = [...longTestIds('WideTest', 1000), 'P\Tests\Unit\NarrowTest::__pest_evaluable_it_c'];
    $path = coverageFile([
        'Provider.php' => [10 => array_fill_keys(range(0, 999), 1)],
        'Narrow.php' => [7 => [1000 => 1]],
    ], $testIds);

    expect(new CoverageFilterWidener($path)->widen())->toBe(['Provider.php']);

    $data = new Unserializer()->unserialize($path);

    expect($data['codeCoverage']->lineCoverage())->toBe(['Narrow.php' => [7 => [1000 => 1]], 'Provider.php' => [10 => [1001 => 1]]])
        ->and($data['codeCoverage']->testIds()[1001])->toBe('P\Tests\Unit\WideTest::')
        ->and($data['basePath'])->toBe('/srv/checkout')
        ->and(is_file($path.'.widened'))->toBeFalse();
});

it('leaves the coverage file as it is when no file needs widening, and does nothing without one', function (): void {
    $path = coverageFile(['Narrow.php' => [7 => [0 => 1]]], ['P\Tests\Unit\NarrowTest::__pest_evaluable_it_c']);
    $before = (string) file_get_contents($path);

    expect(new CoverageFilterWidener($path)->widen())->toBe([])
        ->and(file_get_contents($path))->toBe($before)
        ->and(new CoverageFilterWidener(dirname($path).'/missing.php')->widen())->toBe([])
        ->and(new CoverageFilterWidener('')->widen())->toBe([]);
});
