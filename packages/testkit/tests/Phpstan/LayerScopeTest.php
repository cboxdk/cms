<?php

declare(strict_types=1);

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

it('gives each kind of loose type its own identifier', function (): void {
    expect(array_map(static fn (LooseType $type): string => $type->identifier(), LooseType::cases()))
        ->toBe(['cboxCms.untypedArray', 'cboxCms.arrayShape', 'cboxCms.mixed']);
});
