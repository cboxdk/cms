<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\PanelThemes;

use Cbox\Cms\Contracts\Addons\AddonCapabilities;
use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelApiVersion;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Core\PanelThemes\Actions\CompilePanelThemes;
use Cbox\Cms\Core\PanelThemes\Adapter\FileThemeSources;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenCatalogue;
use Cbox\Cms\Core\Tests\PanelThemes\Fakes\FakeThemeSources;

/**
 * A world for the panel's themes (PRD 13.4): the kit's real token catalogue, js/ui-kit/tokens.json,
 * so the contrast pairs are the ones the panel draws, theme files in memory by path, and addon
 * manifests that ship themes.
 */
final class ThemeWorld
{
    /** The application's theme file. */
    public const string APP = '/srv/app/resources/panel/theme.json';

    /** The brand theme the addon ships. */
    public const string BRAND = '/srv/addons/brand/resources/panel/theme.json';

    /** The addon's second theme, which no test selects unless it says so. */
    public const string LOUD = '/srv/addons/brand/resources/panel/loud.json';

    public const string ADDON = 'acme/cms-brand';

    /** A green accent that keeps every pair at AA in both modes, with a quieter task screen. */
    public const string GREEN = '{"tokens":{"color-accent":{"light":"#1d6b47","dark":"#7fd0a6"},"radius-md":"4px"},"parts":{"task-screen":{"color-surface-raised":{"light":"#f4f8f6","dark":"#14201a"}}}}';

    /** A darker green accent and rounder buttons, which sets color-accent as GREEN does. */
    public const string FOREST = '{"tokens":{"color-accent":{"light":"#14532d","dark":"#86efac"},"button-radius":"9999px"}}';

    /** A pale accent: link text on the surface at 1.16:1 in the light mode, below AA. */
    public const string PALE = '{"tokens":{"color-accent":"#eeeeee"}}';

    private static ?TokenCatalogue $catalogue = null;

    public static function catalogue(): TokenCatalogue
    {
        return self::$catalogue ??= new FileThemeSources()->catalogue();
    }

    /**
     * @param  array<string, string>  $files  JSON by path
     */
    public static function sources(array $files = []): FakeThemeSources
    {
        return new FakeThemeSources(self::catalogue(), $files);
    }

    /**
     * @param  array<string, string>  $files  JSON by path
     */
    public static function compiler(array $files = []): CompilePanelThemes
    {
        return new CompilePanelThemes(self::sources($files));
    }

    /**
     * The addon's manifest, which ships the themes brand and loud.
     *
     * @param  array<string, string>|null  $themes
     */
    public static function addon(bool $uiTheme = true, ?array $themes = null, string $namespace = 'brand', string $package = self::ADDON): AddonManifest
    {
        return new AddonManifest(
            $package,
            new AddonNamespace($namespace),
            new CoreApiVersion(CoreApiVersion::CURRENT_MAJOR, CoreApiVersion::CURRENT_MINOR),
            __DIR__,
            new AddonCapabilities(uiTheme: $uiTheme),
            panel: new PanelContributions(PanelApiVersion::current(), themes: $themes ?? ['brand' => self::BRAND, 'loud' => self::LOUD]),
        );
    }
}
