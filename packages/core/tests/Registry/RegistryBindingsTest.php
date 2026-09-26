<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Cli\CliServiceProvider;
use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Core\CoreServiceProvider;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Adapter\FileRegistryCache;
use Cbox\Cms\Core\Registry\Boundary\ProviderScanRoots;
use Cbox\Cms\Core\Registry\Domain\DeclarationScanner;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Registry\Infrastructure\AttributeScanner;
use Cbox\Cms\Core\Tests\Registry\Providers\DeferredRootProvider;
use Cbox\Cms\Core\Tests\Registry\Providers\FixtureRootProvider;
use Cbox\Cms\Generators\GeneratorsServiceProvider;
use Cbox\Cms\Http\HttpServiceProvider;

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

/**
 * The scan roots of the four package providers, which every application has.
 */
function packageScanRoots(): ScanRoots
{
    $packages = dirname(__DIR__, 3);

    return new ScanRoots(
        new ScanRoot('cboxdk/cms-core', $packages.'/core/src'),
        new ScanRoot('cboxdk/cms-http', $packages.'/http/src'),
        new ScanRoot('cboxdk/cms-cli', $packages.'/cli/src'),
        new ScanRoot('cboxdk/cms-generators', $packages.'/generators/src'),
    );
}

it('lets each package provider declare its own src directory as a scan root', function (): void {
    $packages = dirname(__DIR__, 3);

    expect(new CoreServiceProvider(app())->scanRoots())->toEqual([new ScanRoot(CoreServiceProvider::PACKAGE, $packages.'/core/src')])
        ->and(new HttpServiceProvider(app())->scanRoots())->toEqual([new ScanRoot(HttpServiceProvider::PACKAGE, $packages.'/http/src')])
        ->and(new CliServiceProvider(app())->scanRoots())->toEqual([new ScanRoot(CliServiceProvider::PACKAGE, $packages.'/cli/src')])
        ->and(new GeneratorsServiceProvider(app())->scanRoots())->toEqual([new ScanRoot(GeneratorsServiceProvider::PACKAGE, $packages.'/generators/src')]);
});

it('collects the scan roots of every registered provider that declares them', function (): void {
    $roots = ProviderScanRoots::of(app())->roots;

    expect($roots)->toHaveCount(4)
        ->and(array_map(static fn (ScanRoot $root): string => $root->package, $roots))->toEqualCanonicalizing([
            'cboxdk/cms-core', 'cboxdk/cms-http', 'cboxdk/cms-cli', 'cboxdk/cms-generators',
        ]);

    app()->register(FixtureRootProvider::class);

    expect(ProviderScanRoots::of(app())->roots)->toHaveCount(5)->toContainEqual(RegistryFixtures::root('Valid'));
});

it('registers deferred providers first, so their scan roots are not missed', function (): void {
    app()->addDeferredServices([DeferredRootProvider::SERVICE => DeferredRootProvider::class]);

    expect(app()->getProviders(DeferredRootProvider::class))->toBe([])
        ->and(ProviderScanRoots::of(app())->roots)->toContainEqual(new ScanRoot('acme/deferred', __DIR__.'/Providers'));
});

it('scans the packages\' own classes without a problem; none of them is declared yet', function (): void {
    $registry = RegistryFixtures::builder(RegistryFixtures::scratch())->build(packageScanRoots());

    expect($registry)->toEqual(CompiledRegistry::empty());
});

it('binds the scanner and a cache in the application\'s bootstrap/cache/cms', function (): void {
    expect(app(DeclarationScanner::class))->toBeInstanceOf(AttributeScanner::class)
        ->and(app(RegistryCache::class))->toBeInstanceOf(FileRegistryCache::class)
        ->and(app(RegistryCache::class)->location())->toBe(app()->bootstrapPath('cache/cms'))
        ->and(app(BuildRegistry::class)->location())->toBe(app()->bootstrapPath('cache/cms'));
});

it('loads the registry at run time from the files cms:build wrote, once per process', function (): void {
    $directory = RegistryFixtures::scratch();
    app()->instance(RegistryCache::class, RegistryFixtures::cache($directory));
    app()->forgetInstance(CompiledRegistry::class);

    $built = app(BuildRegistry::class)->build(new ScanRoots(RegistryFixtures::root('Valid')));

    expect(app(CompiledRegistry::class))->toEqual($built)
        ->and(app(CompiledRegistry::class))->toBe(app(CompiledRegistry::class));
});
