<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests;

use Cbox\Cms\Contracts\Build\ScanRoot;
use Cbox\Cms\Panel\Domain\Dto\PanelBuild;
use Cbox\Cms\Panel\PanelServiceProvider;
use Cbox\Cms\Panel\Views\PanelRootView;
use Illuminate\View\Factory;

/*
 * The panel module in the workbench application (PRD 13.4): its views under cms-panel, the build
 * read from packages/panel/dist only when a panel page or file asks for it, so an installation
 * without the build boots, and its classes as a scan root of cms:build.
 */

it('loads its views under cms-panel, with the root view of the panel\'s pages', function (): void {
    expect(realpath(app(Factory::class)->getFinder()->find(PanelRootView::VIEW)))->toBe(realpath(__DIR__.'/../resources/views/app.blade.php'))
        ->and(PanelServiceProvider::VIEWS)->toBe('cms-panel');
});

it('reads the build from packages/panel/dist, which git ignores, and only when it is asked for', function (): void {
    expect(PanelServiceProvider::buildDirectory())->toBe(realpath(__DIR__.'/..').'/dist')
        ->and(app()->bound(PanelBuild::class))->toBeTrue()
        ->and(app()->resolved(PanelBuild::class))->toBeFalse()
        ->and((string) file_get_contents(dirname(__DIR__, 3).'/.gitignore'))->toContain("/packages/panel/dist/\n");
});

it('declares its classes as a scan root of cms:build', function (): void {
    $roots = new PanelServiceProvider(app())->scanRoots();

    expect($roots)->toHaveCount(1)
        ->and($roots[0])->toBeInstanceOf(ScanRoot::class)
        ->and($roots[0]->package)->toBe(PanelServiceProvider::PACKAGE)
        ->and(realpath($roots[0]->directory))->toBe(realpath(__DIR__.'/../src'));
});
