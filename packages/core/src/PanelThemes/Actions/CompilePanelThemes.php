<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Actions;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelContributions;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\Theme;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeCompilation;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeSelection;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenOverlap;
use Cbox\Cms\Core\PanelThemes\Domain\InvalidTheme;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeChecks;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeComposer;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeSources;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeStylesheet;
use Cbox\Cms\Core\PanelThemes\Domain\TokenCatalogueUnavailable;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildWarning;

/**
 * Compiles the panel's theme for cms:build (PRD 13.4): reads the themes the installation selects in
 * cbox-cms.panel.themes, `app` from cbox-cms.panel.app_theme and `<namespace>:<name>` from the
 * manifest of an addon the installation allows, checks each against the token catalogue, composes
 * them in their order, a later theme over an earlier one, checks the composition (ThemeChecks) and
 * renders its stylesheet. A theme the selection does not name is never read and has no effect.
 *
 * It refuses, as registry_panel_theme_invalid: a manifest that ships a theme without
 * AddonCapabilities::$uiTheme, whether the theme is selected or not; a selection that names a
 * theme twice, an addon that is not installed and allowed, a theme the addon does not ship, or
 * `app` without cbox-cms.panel.app_theme; and a theme file that cannot be read or is not of
 * theme.v1.json's form. It refuses a composition below WCAG 2.2 AA as
 * registry_panel_theme_contrast. Each token more than one selected theme sets in one place is a
 * warning, registry_panel_theme_overlap. A theme sets tokens only: nothing it holds reaches the
 * installation's name, logo or favicon.
 */
#[Experimental]
final readonly class CompilePanelThemes
{
    public function __construct(private ThemeSources $sources) {}

    /**
     * @param  list<AddonManifest>  $manifests  the manifests of the addons the installation allows
     */
    public function compile(ThemeSelection $selection, array $manifests): ThemeCompilation
    {
        $problems = [];
        $byNamespace = [];

        foreach ($manifests as $manifest) {
            $byNamespace[$manifest->namespace->value] = $manifest;

            if ($manifest->panel instanceof PanelContributions && $manifest->panel->themes !== [] && ! $manifest->capabilities->uiTheme) {
                $problems[] = $this->invalid(sprintf(
                    'Addon "%s" (%s) ships the panel themes %s, and its AddonCapabilities do not grant uiTheme. Setting the panel\'s tokens needs the capability: give the manifest new AddonCapabilities(uiTheme: true), or remove the themes.',
                    $manifest->namespace->value,
                    $manifest->package,
                    implode(', ', array_keys($manifest->panel->themes)),
                ));
            }
        }

        if ($selection->themes === []) {
            return new ThemeCompilation('', $problems);
        }

        $files = [];

        foreach ($selection->themes as $name) {
            if (isset($files[$name->value])) {
                $problems[] = $this->invalid(sprintf('cbox-cms.panel.themes selects the theme %s twice. Select each theme once, in the order they compose.', $name->value));

                continue;
            }

            $file = $this->file($name, $selection, $byNamespace, $problems);

            if ($file !== null) {
                $files[$name->value] = [$name, $file];
            }
        }

        if ($problems !== []) {
            return new ThemeCompilation('', $problems);
        }

        try {
            $catalogue = $this->sources->catalogue();
        } catch (TokenCatalogueUnavailable $unavailable) {
            return new ThemeCompilation('', [$this->invalid($unavailable->getMessage())]);
        }

        $themes = [];

        foreach ($files as [$name, $file]) {
            try {
                $themes[] = $this->sources->theme($name, $file, $catalogue);
            } catch (InvalidTheme $invalid) {
                foreach ($invalid->reasons as $reason) {
                    $problems[] = $this->invalid(sprintf('The theme %s (%s) cannot be used. %s', $name->value, $file, $reason));
                }
            }
        }

        if ($problems !== []) {
            return new ThemeCompilation('', $problems);
        }

        $composed = ThemeComposer::compose($catalogue, $themes);
        $subject = sprintf('The panel\'s theme, %s composed in that order,', implode(', ', array_map(static fn (Theme $theme): string => $theme->name->value, $themes)));
        $problems = ThemeChecks::problems($composed, $subject);
        $warnings = array_map(
            static fn (TokenOverlap $overlap): BuildWarning => new BuildWarning(BuildWarning::CODE_THEME_OVERLAP, sprintf(
                'The themes %s all set --cms-%s %s; %s, the last of them in cbox-cms.panel.themes, wins.',
                implode(', ', array_map(static fn (ThemeName $name): string => $name->value, $overlap->themes)),
                $overlap->token,
                $overlap->part === null ? 'on the whole panel' : 'on the part hook '.$overlap->part,
                $overlap->themes[count($overlap->themes) - 1]->value,
            )),
            $composed->overlaps,
        );

        return new ThemeCompilation($problems === [] ? ThemeStylesheet::render($composed) : '', $problems, $warnings);
    }

    /**
     * The file of the selected theme, or null with a problem when the selection cannot have it.
     *
     * @param  array<string, AddonManifest>  $manifests  by namespace
     * @param  list<BuildProblem>  $problems
     */
    private function file(ThemeName $name, ThemeSelection $selection, array $manifests, array &$problems): ?string
    {
        if ($name->isApp()) {
            if ($selection->appTheme === null) {
                $problems[] = $this->invalid('cbox-cms.panel.themes selects app, the application\'s own theme, and cbox-cms.panel.app_theme names no file. Name the absolute path of the application\'s theme JSON there, or leave app out of the selection.');
            }

            return $selection->appTheme;
        }

        $manifest = $manifests[(string) $name->addon()] ?? null;
        $panel = $manifest?->panel;

        if (! $manifest instanceof AddonManifest) {
            $problems[] = $this->invalid(sprintf('cbox-cms.panel.themes selects %s, and no addon the installation allows has the namespace %s. Install the addon and allow it in cbox-cms.addons.allowed, or leave the theme out.', $name->value, (string) $name->addon()));

            return null;
        }

        $file = $panel instanceof PanelContributions ? ($panel->themes[(string) $name->local()] ?? null) : null;

        if ($file === null) {
            $problems[] = $this->invalid(sprintf(
                'cbox-cms.panel.themes selects %s, and addon "%s" (%s) ships no theme named %s; it ships %s.',
                $name->value,
                $manifest->namespace->value,
                $manifest->package,
                (string) $name->local(),
                $panel instanceof PanelContributions && $panel->themes !== [] ? implode(', ', array_keys($panel->themes)) : 'none',
            ));

            return null;
        }

        return $manifest->capabilities->uiTheme ? $file : null;
    }

    private function invalid(string $message): BuildProblem
    {
        return new BuildProblem(BuildErrorCode::PanelThemeInvalid, $message);
    }
}
