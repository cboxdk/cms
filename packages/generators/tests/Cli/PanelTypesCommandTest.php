<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Cli;

use Cbox\Cms\Cli\Tests\Console\WorkbenchRegistry;
use Cbox\Cms\Core\Pipeline\Domain\CommandCodecs;
use Cbox\Cms\Core\Reads\Domain\QueryCodecs;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Generators\Cli\Console\PanelTypesCommand;
use Cbox\Cms\Generators\PanelTypes\Adapter\RegistryAddonUiSource;
use Cbox\Cms\Generators\PanelTypes\Domain\AddonUiSource;
use Cbox\Cms\Generators\PanelTypes\Domain\ContributionsModule;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\Phpstan;
use Illuminate\Contracts\Console\Kernel;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;

/*
 * cms:panel:types in the testbench application (PRD 13.4): for the workbench's fixture addon, from
 * the registry compiled from the installation's scan roots and manifests as cms:build compiles
 * it, into a scratch copy of the addon's package. It writes exactly the committed
 * workbench/addons/fixtureaddon/resources/panel/generated/contributions.ts, and a second run
 * changes nothing; it refuses a namespace no addon has with the catalog's exit code.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * Binds the source to the workbench's registry, with every package's directory in the scratch
 * directory, and gives the directory.
 */
function panelTypesScratch(): string
{
    WorkbenchRegistry::bind();
    $scratch = SchemaFixtures::scratch();
    app()->bind(AddonUiSource::class, static fn (): RegistryAddonUiSource => new RegistryAddonUiSource(
        app(RegistryCache::class),
        app(CommandCodecs::class),
        app(QueryCodecs::class),
        static fn (string $package): string => $scratch.'/'.$package,
    ));

    return $scratch;
}

it('writes contributions.ts for the fixture addon, and a second run changes nothing', function (): void {
    $scratch = panelTypesScratch();
    $path = ContributionsModule::DIRECTORY.'/'.ContributionsModule::FILE;
    $kernel = app(Kernel::class);

    $first = $kernel->call('cms:panel:types', ['namespace' => FixtureAddonServiceProvider::NAMESPACE]);
    $firstOutput = $kernel->output();
    $written = (string) file_get_contents($scratch.'/'.FixtureAddonServiceProvider::PACKAGE.'/'.$path);

    $second = $kernel->call('cms:panel:types', ['namespace' => FixtureAddonServiceProvider::NAMESPACE]);

    expect($first)->toBe(0)
        ->and($firstOutput)->toContain('written: '.$path)
        ->and($written)->toBe((string) file_get_contents(Phpstan::root().'/workbench/addons/fixtureaddon/'.$path))
        ->and($written)->toContain('export type Contributions = NoContributions;')
        ->and($second)->toBe(0)
        ->and($kernel->output())->toContain('The panel types of fixtureaddon are current')
        ->and($kernel->output())->not->toContain('written:');
});

it('refuses a namespace no installed addon has, and one that is no namespace', function (string $namespace, int $exit, string $message): void {
    panelTypesScratch();
    $kernel = app(Kernel::class);

    expect($kernel->call('cms:panel:types', ['namespace' => $namespace]))->toBe($exit)
        ->and($kernel->output())->toContain($message);
})->with([
    'not installed' => ['reviews', 64, '[generate_panel_addon_unknown] No installed addon has the namespace reviews.'],
    'reserved' => ['app', 64, 'app'],
    'not a namespace' => ['Reviews', 64, 'Reviews'],
]);

it('is registered with the generators', function (): void {
    expect(app(Kernel::class)->all())->toHaveKey('cms:panel:types')
        ->and(app(Kernel::class)->all()['cms:panel:types'])->toBeInstanceOf(PanelTypesCommand::class);
});
