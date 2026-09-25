<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\PackageManifest;

/**
 * The PSR-4 namespaces of every package, the monorepo tests and the workbench.
 *
 * Pest resolves a namespace through the Composer autoloader. There is no mapping for the
 * bare Cbox\Cms prefix, so it must list each package namespace, or the packages are skipped.
 *
 * @return list<string>
 */
function codeNamespaces(): array
{
    $namespaces = ['Cbox\Cms\Tests', 'Workbench\App'];

    foreach (glob(__DIR__.'/../packages/*/composer.json') ?: [] as $manifest) {
        foreach (array_keys(PackageManifest::of(basename(dirname($manifest)))->psr4()) as $prefix) {
            $namespaces[] = rtrim($prefix, '\\');
        }
    }

    return $namespaces;
}

arch('every class in the packages and the workbench declares strict types', function (): void {
    expect(codeNamespaces())->toHaveCount(8)->toUseStrictTypes();
});

arch('no debug helpers are left in the code', function (): void {
    expect(['dd', 'dump', 'ddd', 'ray', 'var_dump'])->not->toBeUsed();
});
