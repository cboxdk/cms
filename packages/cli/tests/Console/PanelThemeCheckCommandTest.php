<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Core\Tests\PanelThemes\ThemeWorld;
use Illuminate\Support\Facades\Artisan;

/*
 * cms:panel:theme:check (PRD 13.4): checks one theme file as cms:build checks the selected themes,
 * over the kit's real token catalogue. A theme that passes exits 0 and says what it sets; one that
 * is not of theme.v1.json's form exits 65 with registry_panel_theme_invalid, and one below WCAG 2.2
 * AA exits 65 with registry_panel_theme_contrast, each problem on its own line.
 */

/**
 * @return array{int, string}
 */
function themeCheckCli(string $json, ?string $name = null): array
{
    $directory = sys_get_temp_dir().'/cms-theme-check-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $file = $directory.'/'.($name ?? 'theme.json');
    file_put_contents($file, $json);

    try {
        $status = Artisan::call('cms:panel:theme:check', ['theme' => $file]);

        return [$status, str_replace($file, '<file>', Artisan::output())];
    } finally {
        unlink($file);
        rmdir($directory);
    }
}

it('passes a theme that keeps every pair at AA and says what it sets', function (): void {
    [$status, $output] = themeCheckCli(ThemeWorld::GREEN);

    expect($status)->toBe(0)
        ->and($output)->toBe("The theme <file> passes: it sets 2 tokens on the whole panel and 1 on part hooks, and every contrast pair keeps WCAG 2.2 AA in the light and the dark mode.\n");
});

it('refuses a theme below AA with registry_panel_theme_contrast and exit 65', function (): void {
    [$status, $output] = themeCheckCli(ThemeWorld::PALE);

    expect($status)->toBe(65)
        ->and($output)->toStartWith('[registry_panel_theme_contrast] The theme <file> draws --cms-color-accent on --cms-color-surface (text) at 1.16:1 in the light mode on the whole panel')
        ->and(substr_count($output, '[registry_panel_theme_contrast]'))->toBe(5);
});

it('refuses a theme that is not of theme.v1.json\'s form with registry_panel_theme_invalid and exit 65', function (): void {
    [$status, $output] = themeCheckCli('{"name":"Acme","tokens":{"color-accent":{"light":"#14532d"}}}');

    expect($status)->toBe(65)
        ->and($output)->toBe(
            "[registry_panel_theme_invalid] The theme <file> cannot be used. /name: a theme has only tokens and parts, and sets nothing but token values.\n"
            ."[registry_panel_theme_invalid] The theme <file> cannot be used. /tokens/color-accent: give one color for both modes, or an object with exactly light and dark, both of them.\n",
        );
});

it('refuses a file it cannot read', function (): void {
    $status = Artisan::call('cms:panel:theme:check', ['theme' => '/no/such/theme.json']);

    expect($status)->toBe(65)
        ->and(Artisan::output())->toBe("[registry_panel_theme_invalid] The theme /no/such/theme.json cannot be used. The file /no/such/theme.json is not a readable local file.\n");
});
