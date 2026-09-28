<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Phpstan;

use Cbox\Cms\Testkit\Phpstan\LayerScope;
use Cbox\Cms\Testkit\Phpstan\LooseType;

/*
 * Which namespaces the testkit's PHPStan rules check (GUARDRAILS 2.2 and 2.5).
 */

it('allows loose types only where the innermost layer segment is Boundary or Adapter', function (bool $allowed, string $namespace): void {
    expect(LayerScope::allowsLooseTypes($namespace))->toBe($allowed);
})->with([
    [true, 'Cbox\Cms\Http\Boundary'],
    [true, 'Cbox\Cms\Http\Boundary\Parsers'],
    [true, 'Cbox\Cms\Core\Receipts\Adapter'],
    [true, 'Cbox\Cms\Core\Receipts\Adapter\Postgres'],
    [true, 'Cbox\Cms\Core\Domain\Boundary'],
    [false, 'Cbox\Cms\Core\Boundary\Domain'],
    [false, 'Cbox\Cms\Core\Adapter\Actions'],
    [false, 'Cbox\Cms\Core\Entries\Domain'],
    [false, 'Cbox\Cms\Core\Entries\Infrastructure'],
    [false, 'Cbox\Cms\Core\BoundaryHelpers'],
    [false, 'Cbox\Cms\Core'],
    [false, 'Cbox\Cms\Contracts'],
    [false, ''],
]);

it('treats a Tests segment and the global namespace as test code', function (bool $test, string $namespace): void {
    expect(LayerScope::isTestCode($namespace))->toBe($test);
})->with([
    [true, ''],
    [true, 'Cbox\Cms\Tests'],
    [true, 'Cbox\Cms\Core\Tests\Fixtures'],
    [false, 'Cbox\Cms\Core\TestsSupport'],
    [false, 'Cbox\Cms\Testkit\Phpstan'],
    [false, 'Workbench\App\Providers'],
]);

it('treats a Tests segment, and the global namespace only below a tests directory, as a test file', function (bool $test, string $namespace, string $file): void {
    expect(LayerScope::isTestFile($namespace, $file))->toBe($test);
})->with([
    'a Pest file in tests/' => [true, '', '/app/tests/Feature/ThingTest.php'],
    'a Pest file in a package' => [true, '', '/repo/packages/core/tests/Unit/ThingTest.php'],
    'a Pest file with backslashes' => [true, '', 'C:\\app\\tests\\ThingTest.php'],
    'a Pest file by a relative path' => [true, '', 'tests/Feature/ThingTest.php'],
    'a migration by a relative path' => [false, '', 'database/migrations/2026_01_01_000000_create_things_table.php'],
    'a Tests namespace anywhere' => [true, 'Acme\\Shop\\Tests\\Support', '/app/src/Support/Helper.php'],
    'a migration' => [false, '', '/app/database/migrations/2026_01_01_000000_create_things_table.php'],
    'a route file' => [false, '', '/app/routes/web.php'],
    'a config file' => [false, '', '/repo/packages/core/config/cbox-cms.php'],
    'a Pest file in examples/' => [false, '', '/repo/examples/Unit/Clock/FakeClockTest.php'],
    'a file named tests' => [false, '', '/app/bin/tests'],
    'a directory that only starts with tests' => [false, '', '/app/tests-support/stamp.php'],
    'a namespace without Tests below tests/' => [false, 'Acme\\Shop\\Support', '/app/tests/Support/Helper.php'],
    'a TestsSupport namespace' => [false, 'Acme\\TestsSupport', '/app/src/Helper.php'],
]);

it('gives each kind of loose type its own identifier', function (): void {
    expect(array_map(static fn (LooseType $type): string => $type->identifier(), LooseType::cases()))
        ->toBe(['cboxCms.untypedArray', 'cboxCms.arrayShape', 'cboxCms.mixed']);
});
