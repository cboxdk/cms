<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Console;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\PanelThemes\Actions\CheckPanelTheme;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeCheckRequest;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * `cms:panel:theme:check <theme>` (PRD 13.4): checks one panel theme file as cms:build checks the
 * themes the installation selects, on its own over the kit's token catalogue: its form against
 * theme.v1.json, then the contrast of every pair of the catalogue and the size of a pointer target
 * after it is composed, in the light and the dark mode. It calls the action CheckPanelTheme and
 * prints each problem with its code, or the number of values the theme sets.
 *
 * Exit codes: 0 when the theme passes, and otherwise the exit code of its first problem's catalog
 * entry, 65 for registry_panel_theme_invalid and registry_panel_theme_contrast.
 */
#[Internal]
#[Description('Check a panel theme file against the token catalogue and WCAG 2.2 AA contrast, as cms:build checks the selected themes')]
#[Signature('cms:panel:theme:check
        {theme : The theme\'s JSON file, such as resources/panel/theme.json}')]
final class PanelThemeCheckCommand extends Command
{
    public function handle(CheckPanelTheme $check): int
    {
        $file = (string) $this->argument('theme');
        $absolute = str_starts_with($file, '/') ? $file : (getcwd()).'/'.$file;
        $report = $check->check(new ThemeCheckRequest($absolute));

        if ($report->passed()) {
            $this->info(sprintf(
                'The theme %s passes: it sets %d %s on the whole panel and %d on part hooks, and every contrast pair keeps WCAG 2.2 AA in the light and the dark mode.',
                $report->file,
                $report->tokens,
                $report->tokens === 1 ? 'token' : 'tokens',
                $report->partTokens,
            ));

            return self::SUCCESS;
        }

        foreach ($report->problems as $problem) {
            $this->error($problem->describe());
        }

        return ErrorCode::from($report->problems[0]->code->value)->entry()->exit->value;
    }
}
