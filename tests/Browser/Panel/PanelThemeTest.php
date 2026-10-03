<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Core\PanelThemes\Actions\CompilePanelThemes;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeSelection;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\Registry\Boundary\BuildSettingsConfig;
use Cbox\Cms\Panel\Domain\Dto\PanelTheme;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Illuminate\Contracts\Config\Repository;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;

/*
 * The panel's themes in Chromium (PRD 13.4): the workbench's fixture addon ships the theme brand,
 * a magenta accent, and the workbench does not select it, so it has no effect: the page keeps the
 * kit's tokens and links no theme stylesheet. Selected, the theme's stylesheet is served from the
 * panel's own origin under the policy, sets the accent in the cascade layer cms.theme over the
 * kit's tokens in both modes and the part hook it names, and the page stays without an axe
 * finding. Each theme is compiled from the installation's selection as cms:build compiles it.
 */

/**
 * Compiles the panel's theme from the selection, with the fixture addon's manifest, and serves
 * its stylesheet, as cms:build and the panel do.
 */
function serveTheme(ThemeSelection $selection): void
{
    $manifest = new FixtureAddonServiceProvider(app())->addonManifest();
    $compiled = app(CompilePanelThemes::class)->compile($selection, [$manifest]);

    expect($compiled->problems)->toBe([]);

    app()->instance(PanelTheme::class, new PanelTheme($compiled->stylesheet === '' ? null : $compiled->stylesheet));
}

/**
 * The value of a token on the element the selector picks.
 */
function tokenOn(AwaitableWebpage|PendingAwaitablePage $page, string $selector, string $token): string
{
    $value = $page->script(sprintf('getComputedStyle(document.querySelector(%s)).getPropertyValue(%s).trim()', json_encode($selector, JSON_THROW_ON_ERROR), json_encode('--cms-'.$token, JSON_THROW_ON_ERROR)));

    return is_string($value) ? $value : '';
}

/**
 * The addresses of the page's stylesheets that are the theme's.
 *
 * @return list<string>
 */
function themeLinks(AwaitableWebpage|PendingAwaitablePage $page): array
{
    $links = $page->script('[...document.querySelectorAll(\'link[rel="stylesheet"]\')].map((link) => new URL(link.href).pathname).filter((path) => path.startsWith("/cms/theme/"))');

    return is_array($links) ? array_values(array_filter($links, is_string(...))) : [];
}

it('gives an addon\'s theme that the installation does not select no effect', function (): void {
    $manifest = new FixtureAddonServiceProvider(app())->addonManifest();

    expect(array_keys($manifest->panel->themes ?? []))->toBe([FixtureAddonServiceProvider::THEME])
        ->and(app(Repository::class)->get(BuildSettingsConfig::THEMES, []))->toBe([]);

    serveTheme(BuildSettingsConfig::read(app(Repository::class))->themes);

    $page = visit('/cms/login')->inLightMode();

    PanelPage::assertPage($page, ['panel.login.title']);

    expect(themeLinks($page))->toBe([])
        ->and(tokenOn($page, ':root', 'color-accent'))->toBe('oklch(45% .16 258)')
        ->and(tokenOn($page, '[data-cms-part="task-screen"]', 'color-surface-raised'))->toBe('oklch(97.5% .008 250)')
        ->and(tokenOn($page, ':root', 'radius-md'))->toBe('8px');
});

it('applies the addon\'s theme once the installation selects it, in both modes and on its part hook', function (): void {
    serveTheme(new ThemeSelection([new ThemeName('fixtureaddon:brand')]));

    $light = visit('/cms/login')->inLightMode();

    PanelPage::assertPage($light, ['panel.login.title']);

    expect(themeLinks($light))->toHaveCount(1)
        ->and(themeLinks($light)[0] ?? '')->toMatch('~\A/cms/theme/[0-9a-f]{16}\.css\z~')
        ->and(tokenOn($light, ':root', 'color-accent'))->toBe('#9d174d')
        ->and(tokenOn($light, ':root', 'color-focus'))->toBe('#9d174d')
        ->and(tokenOn($light, '[data-cms-part="task-screen"]', 'color-surface-raised'))->toBe('#fdf2f8')
        ->and(tokenOn($light, ':root', 'radius-md'))->toBe('2px');

    $dark = visit('/cms/login')->inDarkMode();

    PanelPage::assertPage($dark, ['panel.login.title']);

    expect(tokenOn($dark, ':root', 'color-accent'))->toBe('#f9a8d4')
        ->and(tokenOn($dark, '[data-cms-part="task-screen"]', 'color-surface-raised'))->toBe('#1f1219');
});
