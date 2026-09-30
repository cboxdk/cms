<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\ContributedFieldType;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Addons\SchemaContributions;
use Cbox\Cms\Contracts\Schema\TypeName;
use Cbox\Cms\Core\Registry\Boundary\ProviderAddonManifests;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Tests\Registry\Providers\AddonManifestProvider;
use Cbox\Cms\Core\Tests\Registry\Providers\DeferredAddonProvider;
use Cbox\Cms\Core\Tests\Registry\Providers\FixtureRootProvider;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use PHPUnit\Framework\Assert;
use stdClass;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;

/*
 * How cms:build reads the addon manifests of the service providers (PRD 13.1, 13.2): a manifest
 * that cannot be built, or whose directories are not there, is a problem of the build that names
 * the provider, never an exception, so the build lists it with the others.
 */

afterEach(function (): void {
    RegistryFixtures::cleanUp();
});

/**
 * @param  AddonManifest|Closure(): AddonManifest  $manifest
 */
function manifestProvider(AddonManifest|Closure $manifest): AddonManifestProvider
{
    return new AddonManifestProvider($manifest instanceof AddonManifest ? static fn (): AddonManifest => $manifest : $manifest);
}

/**
 * @return list<string> each problem as "<code> <message>"
 */
function manifestProblems(BuildProblem ...$problems): array
{
    return array_values(array_map(static fn (BuildProblem $problem): string => $problem->describe(), $problems));
}

it('reads the manifest of each provider that declares one, in order, and passes over the others', function (): void {
    $reviews = RegistryFixtures::addonManifest();
    $glossary = RegistryFixtures::addonManifest('glossary', 'acme/cms-glossary');

    $read = ProviderAddonManifests::from([manifestProvider($reviews), new stdClass, manifestProvider($glossary)]);

    expect($read->manifests)->toBe([$reviews, $glossary])
        ->and($read->problems)->toBe([]);
});

it('reports a reserved namespace as registry_reserved_namespace with the provider', function (string $namespace): void {
    $read = ProviderAddonManifests::from([manifestProvider(static fn (): AddonManifest => RegistryFixtures::addonManifest($namespace))]);

    expect($read->manifests)->toBe([])
        ->and(array_map(static fn (BuildProblem $problem): BuildErrorCode => $problem->code, $read->problems))->toBe([BuildErrorCode::ReservedNamespace])
        ->and(manifestProblems(...$read->problems)[0])->toContain(sprintf('The service provider %s declares an addon manifest with a reserved namespace. The addon namespace "%s" is reserved', AddonManifestProvider::class, $namespace));
})->with(['app', 'ext']);

it('reports a manifest that cannot be built as registry_invalid_manifest with the provider and the reason', function (Closure $manifest, string $reason): void {
    $read = ProviderAddonManifests::from([manifestProvider($manifest)]);

    expect($read->manifests)->toBe([])
        ->and(array_map(static fn (BuildProblem $problem): BuildErrorCode => $problem->code, $read->problems))->toBe([BuildErrorCode::InvalidManifest])
        ->and(manifestProblems(...$read->problems)[0])->toContain(sprintf('The service provider %s declares an addon manifest that cannot be built. ', AddonManifestProvider::class))
        ->toContain($reason);
})->with([
    'a namespace with an underscore' => [static fn (): AddonManifest => RegistryFixtures::addonManifest('acme_reviews'), 'The addon namespace "acme_reviews" is not a lowercase letter'],
    'a namespace of 21 characters' => [static fn (): AddonManifest => RegistryFixtures::addonManifest(str_repeat('a', 21)), 'is not a lowercase letter followed by at most 19'],
    'a field type of another namespace' => [static fn (): AddonManifest => new AddonManifest('acme/cms-reviews', new AddonNamespace('reviews'), CoreApiVersion::current(), __DIR__, schema: new SchemaContributions([new ContributedFieldType('shop:stars')], fieldTypeContributor: AddonFieldTypes::class)), 'contributes the field type "shop:stars", which is outside its namespace'],
]);

it('reports a documentation or schema directory that is not there as registry_invalid_manifest', function (): void {
    $missing = RegistryFixtures::scratch();
    $read = ProviderAddonManifests::from([
        manifestProvider(new AddonManifest('acme/cms-reviews', new AddonNamespace('reviews'), CoreApiVersion::current(), $missing, new AddonCapabilities)),
        manifestProvider(new AddonManifest('acme/cms-glossary', new AddonNamespace('glossary'), CoreApiVersion::current(), __DIR__, schema: new SchemaContributions(types: [new TypeName('glossary:term')], directory: $missing))),
    ]);

    expect($read->manifests)->toBe([])
        ->and(manifestProblems(...$read->problems))->toBe([
            sprintf('[registry_invalid_manifest] The manifest of addon "reviews" (acme/cms-reviews) names the documentation directory %s, which is not a readable directory. Every addon has its documentation (PRD 13.1): point the manifest at directories that exist, built from __DIR__.', $missing),
            sprintf('[registry_invalid_manifest] The manifest of addon "glossary" (acme/cms-glossary) names the schema directory %s, which is not a readable directory. Every addon has its documentation (PRD 13.1): point the manifest at directories that exist, built from __DIR__.', $missing),
        ]);
});

it('reports a field type contributor that is not a class implementing FieldTypeContributor as registry_invalid_manifest', function (string $contributor): void {
    $read = ProviderAddonManifests::from([
        manifestProvider(new AddonManifest('acme/cms-reviews', new AddonNamespace('reviews'), CoreApiVersion::current(), __DIR__, schema: new SchemaContributions([new ContributedFieldType('reviews:stars')], fieldTypeContributor: $contributor))),
        manifestProvider(new AddonManifest('acme/cms-glossary', new AddonNamespace('glossary'), CoreApiVersion::current(), __DIR__, schema: new SchemaContributions([new ContributedFieldType('glossary:stars')], fieldTypeContributor: AddonFieldTypes::class))),
    ]);

    expect(array_map(static fn (AddonManifest $manifest): string => $manifest->namespace->value, $read->manifests))->toBe(['glossary'])
        ->and(manifestProblems(...$read->problems))->toBe([
            sprintf('[registry_invalid_manifest] The manifest of addon "reviews" (acme/cms-reviews) names the field type contributor %s, which is not a class that implements Cbox\Cms\Contracts\FieldTypes\FieldTypeContributor. Name the class that returns the addon\'s field types.', $contributor),
        ]);
})->with([
    'a class that does not exist' => ['Acme\\Reviews\\MissingFieldTypes'],
    'a class that does not implement it' => [stdClass::class],
]);

it('fails the build with registry_reserved_namespace for an addon named app, and writes nothing', function (): void {
    $directory = RegistryFixtures::scratch();
    $addons = ProviderAddonManifests::from([manifestProvider(static fn (): AddonManifest => RegistryFixtures::addonManifest('app'))]);

    try {
        RegistryFixtures::builder($directory)->build(new ScanRoots(RegistryFixtures::root('Valid')), $addons);
        Assert::fail('The build did not fail.');
    } catch (RegistryBuildFailed $failed) {
        expect($failed->codes())->toBe([BuildErrorCode::ReservedNamespace])
            ->and(is_dir($directory))->toBeFalse();
    }
});

it('asks the application\'s providers, deferred ones included, and no provider that declares only scan roots', function (): void {
    $app = app();
    Assert::assertInstanceOf(Application::class, $app);
    $app->register(FixtureRootProvider::class);
    $app->addDeferredServices([DeferredAddonProvider::SERVICE => DeferredAddonProvider::class]);

    $read = ProviderAddonManifests::of($app);

    // The workbench's fixture addon, which package discovery registers, and the deferred one.
    expect($read->manifests)->toEqual([new FixtureAddonServiceProvider($app)->addonManifest(), RegistryFixtures::addonManifest()])
        ->and($read->problems)->toBe([]);
});
