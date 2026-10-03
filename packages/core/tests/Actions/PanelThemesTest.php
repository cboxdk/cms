<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Core\PanelThemes\Actions\CheckPanelTheme;
use Cbox\Cms\Core\PanelThemes\Actions\CompilePanelThemes;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeCheckRequest;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeSelection;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\Registry\Actions\BuildRegistry;
use Cbox\Cms\Core\Registry\Domain\BuildErrorCode;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildWarning;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\DeclaredAddons;
use Cbox\Cms\Core\Registry\Domain\Dto\ScanRoots;
use Cbox\Cms\Core\Registry\Domain\RegistryBuildFailed;
use Cbox\Cms\Core\Registry\Domain\RegistryCompiler;
use Cbox\Cms\Core\Tests\PanelThemes\Fakes\FakeThemeSources;
use Cbox\Cms\Core\Tests\PanelThemes\Fakes\FakeThemeStylesheets;
use Cbox\Cms\Core\Tests\PanelThemes\ThemeWorld;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeContractSchemas;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeDeclarationScanner;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeOpenApiDocuments;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;

/*
 * The panel's themes as cms:build compiles them and cms:panel:theme:check checks one (PRD 13.4,
 * GUARDRAILS 8): the themes cbox-cms.panel.themes selects compose in their order over the kit's
 * real token catalogue, every contrast pair is checked after composition, a theme the selection
 * does not name is never read, and a theme sets tokens only.
 */

/**
 * @param  list<BuildProblem>  $problems
 * @return list<string>
 */
function themeCodes(array $problems): array
{
    return array_values(array_unique(array_map(static fn (BuildProblem $problem): string => $problem->code->value, $problems)));
}

/**
 * @param  list<string>  $names
 */
function selecting(array $names, ?string $app = null): ThemeSelection
{
    return new ThemeSelection(array_map(static fn (string $name): ThemeName => new ThemeName($name), $names), $app);
}

it('composes the selected themes in their order into the cms.theme layer', function (): void {
    $compiled = ThemeWorld::compiler([ThemeWorld::BRAND => ThemeWorld::GREEN, ThemeWorld::APP => ThemeWorld::FOREST])
        ->compile(selecting(['brand:brand', 'app'], ThemeWorld::APP), [ThemeWorld::addon()]);

    expect($compiled->problems)->toBe([])
        ->and($compiled->stylesheet)->toStartWith("/* The panel's theme, written by cms:build from the themes cbox-cms.panel.themes selects, in their order: brand:brand, app. */")
        ->toContain("@layer cms.theme {\n:root {\n--cms-button-radius: 9999px;\n--cms-color-accent: #14532d;\n--cms-radius-md: 4px;\n}")
        ->toContain(":root[data-theme='dark'] {\n--cms-color-accent: #86efac;\n}")
        ->toContain("[data-cms-part='task-screen'] {\n--cms-color-surface-raised: #f4f8f6;\n}")
        ->and(str_contains($compiled->stylesheet, '#1d6b47'))->toBeFalse()
        ->and(array_map(static fn (BuildWarning $warning): string => $warning->describe(), $compiled->warnings))->toBe([
            '[registry_panel_theme_overlap] The themes brand:brand, app all set --cms-color-accent on the whole panel; app, the last of them in cbox-cms.panel.themes, wins.',
        ]);
});

it('fails with registry_panel_theme_contrast on a composed theme below AA, naming the pair, the mode and the place', function (): void {
    $compiled = ThemeWorld::compiler([ThemeWorld::APP => ThemeWorld::PALE])->compile(selecting(['app'], ThemeWorld::APP), []);

    expect(themeCodes($compiled->problems))->toBe(['registry_panel_theme_contrast'])
        ->and($compiled->stylesheet)->toBe('')
        ->and($compiled->problems[0]->message)->toBe('The panel\'s theme, app composed in that order, draws --cms-color-accent on --cms-color-surface (text) at 1.16:1 in the light mode on the whole panel, below the 4.50:1 WCAG 2.2 AA needs. Change the theme\'s value of one of them, in the mode the problem names.')
        ->and(array_map(static fn (BuildProblem $problem): string => $problem->message, $compiled->problems))->each->toContain('light mode');
});

it('checks the contrast after composition, so two themes that pass alone can fail together', function (): void {
    $surface = '{"tokens":{"color-surface":{"light":"#1a1a1a","dark":"#121418"},"color-surface-raised":{"light":"#1a1a1a","dark":"#1b1e24"},"color-text":{"light":"#f5f5f5","dark":"#e8eaee"},"color-text-muted":{"light":"#c8c8c8","dark":"#9aa1ad"},"color-accent":{"light":"#8ab4ff","dark":"#6b8ff0"},"color-accent-hover":{"light":"#a8c6ff","dark":"#87a4f4"},"color-on-accent":{"light":"#0c0e12","dark":"#0c0e12"},"color-danger":{"light":"#ff8a85","dark":"#ef6b66"},"color-border-strong":{"light":"#8a919e","dark":"#6b7280"}}}';
    $accent = '{"tokens":{"color-accent":{"light":"#2f5bd3","dark":"#6b8ff0"}}}';
    $sources = [ThemeWorld::BRAND => $surface, ThemeWorld::APP => $accent];
    $check = new CheckPanelTheme(ThemeWorld::sources($sources));

    expect($check->check(new ThemeCheckRequest(ThemeWorld::BRAND))->passed())->toBeTrue()
        ->and($check->check(new ThemeCheckRequest(ThemeWorld::APP))->passed())->toBeTrue()
        ->and(themeCodes(ThemeWorld::compiler($sources)->compile(selecting(['brand:brand', 'app'], ThemeWorld::APP), [ThemeWorld::addon()])->problems))->toBe(['registry_panel_theme_contrast']);
});

it('checks every part hook a theme names over the whole panel\'s values', function (): void {
    $part = '{"parts":{"task-screen":{"color-surface-raised":{"light":"#2f5bd3","dark":"#1b1e24"}}}}';
    $problems = ThemeWorld::compiler([ThemeWorld::APP => $part])->compile(selecting(['app'], ThemeWorld::APP), [])->problems;

    expect(themeCodes($problems))->toBe(['registry_panel_theme_contrast'])
        ->and($problems[0]->message)->toContain('in the light mode on the part hook task-screen');
});

it('never reads a theme the selection does not name, so an unselected addon theme has no effect', function (): void {
    $sources = ThemeWorld::sources([ThemeWorld::BRAND => ThemeWorld::PALE, ThemeWorld::LOUD => ThemeWorld::PALE, ThemeWorld::APP => ThemeWorld::GREEN]);
    $compiled = new CompilePanelThemes($sources)->compile(selecting(['app'], ThemeWorld::APP), [ThemeWorld::addon()]);

    expect($compiled->problems)->toBe([])
        ->and($sources->read)->toBe([ThemeWorld::APP])
        ->and($compiled->stylesheet)->not->toContain('#eeeeee')
        ->and(new CompilePanelThemes($sources)->compile(new ThemeSelection, [ThemeWorld::addon()]))->toEqual(new CompilePanelThemes($sources)->compile(new ThemeSelection, []));
});

it('writes no stylesheet when no theme is selected', function (): void {
    expect(ThemeWorld::compiler()->compile(new ThemeSelection, [ThemeWorld::addon()])->stylesheet)->toBe('');
});

/**
 * @param  list<AddonManifest>  $manifests
 */
function refusedSelection(ThemeSelection $selection, array $manifests): string
{
    $problems = ThemeWorld::compiler([ThemeWorld::BRAND => ThemeWorld::GREEN])->compile($selection, $manifests)->problems;

    expect(themeCodes($problems))->toBe(['registry_panel_theme_invalid']);

    return implode("\n", array_map(static fn (BuildProblem $problem): string => $problem->message, $problems));
}

it('refuses with registry_panel_theme_invalid what the selection cannot have', function (ThemeSelection $selection, bool $uiTheme, bool $addon, string $message): void {
    expect(refusedSelection($selection, $addon ? [ThemeWorld::addon(uiTheme: $uiTheme)] : []))->toContain($message);
})->with([
    'a theme selected twice' => [selecting(['brand:brand', 'brand:brand']), true, true, 'selects the theme brand:brand twice'],
    'an addon that is not installed or allowed' => [selecting(['stamps:brand']), true, true, 'no addon the installation allows has the namespace stamps'],
    'a theme the addon does not ship' => [selecting(['brand:quiet']), true, true, 'ships no theme named quiet; it ships brand, loud'],
    'app without its file' => [selecting(['app']), true, false, 'cbox-cms.panel.app_theme names no file'],
    'an addon theme without the capability uiTheme' => [new ThemeSelection, false, true, 'Addon "brand" (acme/cms-brand) ships the panel themes brand, loud, and its AddonCapabilities do not grant uiTheme'],
    'a file that cannot be read' => [selecting(['brand:loud']), true, true, 'The theme brand:loud ('.ThemeWorld::LOUD.') cannot be used. The file '.ThemeWorld::LOUD.' is not a readable local file.'],
]);

it('refuses a theme file that is not of theme.v1.json\'s form, with each reason and its place', function (string $json, string $reason): void {
    $problems = ThemeWorld::compiler([ThemeWorld::APP => $json])->compile(selecting(['app'], ThemeWorld::APP), [])->problems;

    expect(themeCodes($problems))->toBe(['registry_panel_theme_invalid'])
        ->and(array_map(static fn (BuildProblem $problem): string => $problem->message, $problems))->toContain('The theme app ('.ThemeWorld::APP.') cannot be used. '.$reason);
})->with([
    'not JSON' => ['{"tokens":', 'It is not JSON: [json_malformed] the document is not well-formed JSON, or nests more than 63 arrays or objects inside one another: Syntax error.'],
    'the installation\'s name' => ['{"name":"Acme","tokens":{}}', '/name: a theme has only tokens and parts, and sets nothing but token values.'],
    'a logo' => ['{"logo":"/srv/app/logo.svg"}', '/logo: a theme has only tokens and parts, and sets nothing but token values.'],
    'a primitive token' => ['{"tokens":{"ref-blue-600":"#000000"}}', '/tokens/ref-blue-600: a primitive token, which only the kit sets; a theme sets only the semantic and component tokens of docs/ui/tokens.md.'],
    'an unknown token' => ['{"tokens":{"color-brand":"#000000"}}', '/tokens/color-brand: not a token of the catalogue; a theme sets only the semantic and component tokens of docs/ui/tokens.md.'],
    'one mode only' => ['{"tokens":{"color-accent":{"light":"#14532d"}}}', '/tokens/color-accent: give one color for both modes, or an object with exactly light and dark, both of them.'],
    'a value of another type' => ['{"tokens":{"radius-md":"#ffffff"}}', '/tokens/radius-md: the value "#ffffff" is not a length of the form docs/ui/tokens.md shows.'],
    'a value that would end the rule' => ['{"tokens":{"color-accent":{"light":"#14532d","dark":"red;}body{display:none"}}}', '/tokens/color-accent: the dark value "red;}body{display:none" is not a color of the form docs/ui/tokens.md shows.'],
    'a part that is not curated' => ['{"parts":{"shell-brand":{"color-text":"#000000"}}}', '/parts/shell-brand: not a curated part hook; a theme may name only status-screen, task-screen.'],
    'a key given twice' => ['{"tokens":{"radius-md":"4px","radius-md":"8px"}}', 'It is not JSON: [json_malformed] an object has the key "radius-md" twice.'],
]);

it('refuses a pointer target smaller than 24 pixels after composition', function (): void {
    $problems = ThemeWorld::compiler([ThemeWorld::APP => '{"tokens":{"target-size":"1rem"}}'])->compile(selecting(['app'], ThemeWorld::APP), [])->problems;

    expect(themeCodes($problems))->toBe(['registry_panel_theme_invalid'])
        ->and($problems[0]->message)->toContain('sets --cms-target-size to 1rem in the light mode on the whole panel, and a pointer target must be at least 24 pixels high (WCAG 2.2, 2.5.8)');
});

it('refuses every theme when the token catalogue cannot be read', function (): void {
    $problems = new CompilePanelThemes(new FakeThemeSources)->compile(selecting(['app'], ThemeWorld::APP), [])->problems;

    expect(themeCodes($problems))->toBe(['registry_panel_theme_invalid'])
        ->and($problems[0]->message)->toBe('The fake holds no token catalogue.');
});

it('checks one theme file on its own, as cms:panel:theme:check does', function (): void {
    $check = new CheckPanelTheme(ThemeWorld::sources([ThemeWorld::BRAND => ThemeWorld::GREEN, ThemeWorld::APP => ThemeWorld::PALE, ThemeWorld::LOUD => '{"tokens":{"ref-white":"#000000"}}']));
    $green = $check->check(new ThemeCheckRequest(ThemeWorld::BRAND));
    $pale = $check->check(new ThemeCheckRequest(ThemeWorld::APP));
    $invalid = $check->check(new ThemeCheckRequest(ThemeWorld::LOUD));

    expect($green->passed())->toBeTrue()
        ->and([$green->tokens, $green->partTokens])->toBe([2, 1])
        ->and(themeCodes($pale->problems))->toBe(['registry_panel_theme_contrast'])
        ->and($pale->problems[0]->message)->toStartWith('The theme '.ThemeWorld::APP.' draws --cms-color-accent on --cms-color-surface (text) at 1.16:1')
        ->and(themeCodes($invalid->problems))->toBe(['registry_panel_theme_invalid'])
        ->and($invalid->problems[0]->message)->toBe('The theme '.ThemeWorld::LOUD.' cannot be used. /tokens/ref-white: a primitive token, which only the kit sets; a theme sets only the semantic and component tokens of docs/ui/tokens.md.');
});

/*
 * cms:build writes the stylesheet with the registry, lists the theme's problems with the
 * registry's own, and writes nothing when either fails.
 */

function themedBuild(FakeRegistryCache $cache, FakeThemeStylesheets $stylesheets, string $app): BuildRegistry
{
    return new BuildRegistry(new FakeDeclarationScanner, new RegistryCompiler, $cache, new FakeOpenApiDocuments, new FakeContractSchemas, ThemeWorld::compiler([ThemeWorld::APP => $app]), $stylesheets);
}

it('writes the stylesheet with the registry and carries the overlaps as warnings', function (): void {
    $cache = new FakeRegistryCache;
    $stylesheets = new FakeThemeStylesheets;
    $registry = themedBuild($cache, $stylesheets, ThemeWorld::GREEN)->build(new ScanRoots, new DeclaredAddons, new BuildSettings(themes: selecting(['app'], ThemeWorld::APP)));

    expect($registry)->toBeInstanceOf(CompiledRegistry::class)
        ->and($cache->writes)->toBe(1)
        ->and($stylesheets->read())->toContain('--cms-color-accent: #1d6b47;');
});

it('writes nothing, not the registry and not the stylesheet, when the composed theme fails', function (): void {
    $cache = new FakeRegistryCache;
    $stylesheets = new FakeThemeStylesheets;

    expect(static fn (): CompiledRegistry => themedBuild($cache, $stylesheets, ThemeWorld::PALE)->build(new ScanRoots, new DeclaredAddons, new BuildSettings(themes: selecting(['app'], ThemeWorld::APP))))
        ->toThrow(RegistryBuildFailed::class, '[registry_panel_theme_contrast]')
        ->and($cache->writes)->toBe(0)
        ->and($stylesheets->writes)->toBe(0);
});

it('leaves out the themes of an addon the installation does not allow', function (): void {
    $stylesheets = new FakeThemeStylesheets;
    $build = new BuildRegistry(new FakeDeclarationScanner, new RegistryCompiler, new FakeRegistryCache, new FakeOpenApiDocuments, new FakeContractSchemas, ThemeWorld::compiler([ThemeWorld::BRAND => ThemeWorld::GREEN]), $stylesheets);

    try {
        $build->build(new ScanRoots, new DeclaredAddons([ThemeWorld::addon()]), new BuildSettings(allowed: [], themes: selecting(['brand:brand'])));
        $codes = [];
    } catch (RegistryBuildFailed $failed) {
        $codes = array_map(static fn (BuildErrorCode $code): string => $code->value, $failed->codes());
    }

    expect($codes)->toContain(BuildErrorCode::PanelThemeInvalid->value)
        ->and($stylesheets->writes)->toBe(0);
});
