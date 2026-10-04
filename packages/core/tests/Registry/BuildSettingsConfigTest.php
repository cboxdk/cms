<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\PanelThemes\Domain\Dto\ThemeSelection;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\Registry\Boundary\BuildSettingsConfig;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\ContributionOverride;
use Cbox\Cms\Core\Registry\Domain\Dto\ReplacementChoice;
use Cbox\Cms\Core\Registry\Domain\PublisherKey;
use Illuminate\Config\Repository;

/*
 * The installation's settings cms:build compiles (PRD 13.8, 13.4): the allowlist of addons and
 * the panel's overrides and replacement choices. A value of the wrong form is a problem of the
 * build, with its code and the setting.
 */

/**
 * @param  array<array-key, mixed>  $addons
 * @param  array<array-key, mixed>  $panel
 */
function buildSettings(array $addons, array $panel = [], ?string $environment = null): BuildSettings
{
    return BuildSettingsConfig::read(new Repository(['cbox-cms' => ['addons' => $addons, 'panel' => $panel], 'app' => ['env' => $environment]]));
}

/** A publisher's Ed25519 public key, the base64 of 32 bytes. */
const PUBLISHER_KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

/**
 * @return list<string>
 */
function settingsProblems(BuildSettings $settings): array
{
    return array_map(static fn (BuildProblem $problem): string => $problem->describe(), $settings->problems);
}

it('reads the allowlist, the overrides and the replacement choices', function (): void {
    $settings = buildSettings(['allowed' => ['acme/cms-approvals']], [
        'contributions' => ['notes.legacy@1' => ['approvals.badge' => ['priority' => 5, 'enabled' => false], 'approvals.seal' => ['enabled' => true]]],
        'replacements' => ['notes.form.any@1' => ['Acme\Reason' => 'approvals.reason-input']],
    ]);

    expect($settings->problems)->toBe([])
        ->and($settings->allowed)->toBe(['acme/cms-approvals'])
        ->and($settings->allows('acme/cms-approvals'))->toBeTrue()
        ->and($settings->allows('acme/cms-stamps'))->toBeFalse()
        ->and(array_map(static fn (ContributionOverride $override): array => [$override->point->toString(), $override->contribution->value, $override->priority, $override->enabled], $settings->overrides))->toBe([
            ['notes.legacy@1', 'approvals.badge', 5, false],
            ['notes.legacy@1', 'approvals.seal', null, true],
        ])
        ->and(array_map(static fn (ReplacementChoice $choice): array => [$choice->point->toString(), $choice->key, $choice->winner->value], $settings->replacements))->toBe([['notes.form.any@1', 'Acme\Reason', 'approvals.reason-input']]);
});

it('reads no allowlist as an empty one, and nothing set for the panel as nothing', function (): void {
    $settings = BuildSettingsConfig::read(new Repository([]));

    expect($settings->allowed)->toBe([])
        ->and($settings->overrides)->toBe([])
        ->and($settings->replacements)->toBe([])
        ->and($settings->problems)->toBe([]);
});

it('reports each setting of the wrong form with its code', function (array $addons, array $panel, string $problem): void {
    expect(settingsProblems(buildSettings($addons, $panel)))->toBe([$problem]);
})->with([
    'an allowlist that is a string' => [['allowed' => 'acme/cms-approvals'], [], '[registry_addon_not_allowed] The setting cbox-cms.addons.allowed must be a list of the Composer packages of the allowed addons; it is string.'],
    'an allowlist that names no package' => [['allowed' => ['approvals']], [], '[registry_addon_not_allowed] The setting cbox-cms.addons.allowed names "approvals", which is not a Composer package name such as "acme/cms-approvals".'],
    'contributions that are a list' => [['allowed' => []], ['contributions' => ['approvals.badge']], '[registry_panel_override_invalid] The setting cbox-cms.panel.contributions must be a map from point ids to maps from contribution ids to settings; it is array.'],
    'a point that is no point id' => [['allowed' => []], ['contributions' => ['notes.legacy' => ['approvals.badge' => ['priority' => 1]]]], '[registry_panel_override_invalid] The setting cbox-cms.panel.contributions names "notes.legacy". "notes.legacy" is not a panel point id: the point\'s name, "@" and its version from 1, such as "account.me.sections@1".'],
    'a priority that is a string' => [['allowed' => []], ['contributions' => ['notes.legacy@1' => ['approvals.badge' => ['priority' => '1']]]], '[registry_panel_override_invalid] The setting cbox-cms.panel.contributions.notes.legacy@1.approvals.badge takes only priority, a whole number, and enabled, a boolean.'],
    'an unknown setting' => [['allowed' => []], ['contributions' => ['notes.legacy@1' => ['approvals.badge' => ['hidden' => true]]]], '[registry_panel_override_invalid] The setting cbox-cms.panel.contributions.notes.legacy@1.approvals.badge takes only priority, a whole number, and enabled, a boolean.'],
    'a winner that is not a string' => [['allowed' => []], ['replacements' => ['notes.form.any@1' => ['Acme\Reason' => 1]]], '[registry_panel_override_invalid] The setting cbox-cms.panel.replacements.notes.form.any@1.Acme\Reason must be the id of the replacement that wins the key; it is int.'],
]);

it('reads the selected panel themes in their order and the application\'s theme file', function (): void {
    $settings = buildSettings(['allowed' => []], ['themes' => ['fixtureaddon:brand', 'app'], 'app_theme' => '/srv/app/resources/panel/theme.json']);

    expect($settings->problems)->toBe([])
        ->and(array_map(static fn (ThemeName $name): string => $name->value, $settings->themes->themes))->toBe(['fixtureaddon:brand', 'app'])
        ->and($settings->themes->appTheme)->toBe('/srv/app/resources/panel/theme.json')
        ->and(BuildSettingsConfig::read(new Repository([]))->themes)->toEqual(new ThemeSelection);
});

it('reports each theme setting of the wrong form as registry_panel_theme_invalid', function (array $panel, string $problem): void {
    expect(settingsProblems(buildSettings(['allowed' => []], $panel)))->toBe([$problem]);
})->with([
    'themes that are a string' => [['themes' => 'app'], '[registry_panel_theme_invalid] The setting cbox-cms.panel.themes must be a list of theme names in the order they compose, such as ["fixtureaddon:brand", "app"]; it is string.'],
    'a theme that is no name' => [['themes' => ['brand']], '[registry_panel_theme_invalid] The setting cbox-cms.panel.themes names a theme it cannot use. "brand" is not the name of a panel theme: app, the application\'s own, or <namespace>:<name> of an addon\'s, such as "fixtureaddon:brand".'],
    'a theme that is no text' => [['themes' => [1]], '[registry_panel_theme_invalid] The setting cbox-cms.panel.themes names a theme it cannot use. int is not the name of a panel theme.'],
    'an app theme that is relative' => [['app_theme' => 'resources/panel/theme.json'], '[registry_panel_theme_invalid] The setting cbox-cms.panel.app_theme must be the absolute path of the application\'s theme JSON, such as base_path(\'resources/panel/theme.json\'), or null; it is "resources/panel/theme.json".'],
    'an app theme that is no JSON file' => [['app_theme' => '/srv/app/theme.css'], '[registry_panel_theme_invalid] The setting cbox-cms.panel.app_theme must be the absolute path of the application\'s theme JSON, such as base_path(\'resources/panel/theme.json\'), or null; it is "/srv/app/theme.css".'],
]);

it('reads the publisher keys the installation trusts by package, and whether the environment is local', function (): void {
    $other = 'MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=';
    $settings = buildSettings(['allowed' => [], 'publishers' => ['acme/cms-approvals' => [PUBLISHER_KEY, $other], 'acme/cms-stamps' => []]], environment: 'local');
    $production = buildSettings(['allowed' => []], environment: 'production');

    expect($settings->problems)->toBe([])
        ->and($settings->signatures?->unsignedAllowed)->toBeTrue()
        ->and(array_map(static fn (array $keys): array => array_map(static fn (PublisherKey $key): string => $key->value, $keys), $settings->signatures->publishers ?? []))->toBe(['acme/cms-approvals' => [PUBLISHER_KEY, $other], 'acme/cms-stamps' => []])
        ->and($production->signatures?->unsignedAllowed)->toBeFalse()
        ->and($production->signatures?->publishers)->toBe([])
        ->and(BuildSettingsConfig::read(new Repository([]))->signatures?->unsignedAllowed)->toBeFalse();
});

it('reports each publisher setting of the wrong form as registry_panel_bundle_unsigned', function (array $addons, string $problem): void {
    expect(settingsProblems(buildSettings($addons)))->toBe([$problem]);
})->with([
    'publishers that are a list' => [['allowed' => [], 'publishers' => [PUBLISHER_KEY]], '[registry_panel_bundle_unsigned] The setting cbox-cms.addons.publishers must be a map from the Composer packages of the addons to lists of their publishers\' Ed25519 public keys, each the base64 of its 32 bytes; it is array.'],
    'a package that is no package name' => [['allowed' => [], 'publishers' => ['approvals' => [PUBLISHER_KEY]]], '[registry_panel_bundle_unsigned] The setting cbox-cms.addons.publishers names "approvals", which is not a Composer package name such as "acme/cms-approvals".'],
    'keys that are a string' => [['allowed' => [], 'publishers' => ['acme/cms-approvals' => PUBLISHER_KEY]], '[registry_panel_bundle_unsigned] The setting cbox-cms.addons.publishers.acme/cms-approvals must be a list of the publisher\'s Ed25519 public keys, each the base64 of its 32 bytes; it is string.'],
    'a key that is too short' => [['allowed' => [], 'publishers' => ['acme/cms-approvals' => ['c2hvcnQ=']]], '[registry_panel_bundle_unsigned] The setting cbox-cms.addons.publishers.acme/cms-approvals names a key it cannot use. "c2hvcnQ=" is not an Ed25519 public key: the 44 base64 characters of its 32 bytes.'],
    'a key that is no text' => [['allowed' => [], 'publishers' => ['acme/cms-approvals' => [32]]], '[registry_panel_bundle_unsigned] The setting cbox-cms.addons.publishers.acme/cms-approvals names a key it cannot use. int is not an Ed25519 public key.'],
]);
