<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Actions;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeCheckReport;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeCheckRequest;
use Cbox\Cms\Core\PanelThemes\Domain\InvalidTheme;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeChecks;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeComposer;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeSources;
use Cbox\Cms\Core\PanelThemes\Domain\TokenCatalogueUnavailable;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;

/**
 * cms:panel:theme:check (PRD 13.4): the checks cms:build makes, on one theme file alone over the
 * catalogue, so an addon's author or an application checks a theme before anyone selects it. The
 * file is read and checked against theme.v1.json's form (registry_panel_theme_invalid), composed on
 * its own and held to WCAG 2.2 AA (registry_panel_theme_contrast, and registry_panel_theme_invalid
 * for the target size). A composition with other themes can still fail where this one passes; the
 * build checks that.
 */
#[Experimental]
final readonly class CheckPanelTheme
{
    public function __construct(private ThemeSources $sources) {}

    public function check(ThemeCheckRequest $request): ThemeCheckReport
    {
        try {
            $catalogue = $this->sources->catalogue();
            $theme = $this->sources->theme(ThemeName::app(), $request->file, $catalogue);
        } catch (TokenCatalogueUnavailable $unavailable) {
            return new ThemeCheckReport($request->file, [new BuildProblem(BuildErrorCode::PanelThemeInvalid, $unavailable->getMessage())]);
        } catch (InvalidTheme $invalid) {
            return new ThemeCheckReport($request->file, array_map(
                static fn (string $reason): BuildProblem => new BuildProblem(BuildErrorCode::PanelThemeInvalid, sprintf('The theme %s cannot be used. %s', $request->file, $reason)),
                $invalid->reasons,
            ));
        }

        $composed = ThemeComposer::compose($catalogue, [$theme]);
        $partTokens = array_sum(array_map(count(...), $theme->parts));

        return new ThemeCheckReport(
            $request->file,
            ThemeChecks::problems($composed, sprintf('The theme %s', $request->file)),
            count($theme->tokens),
            $partTokens,
        );
    }
}
