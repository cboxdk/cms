<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry;

use Cbox\Cms\Core\Registry\Boundary\BuildSettingsConfig;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildProblem;
use Cbox\Cms\Core\Registry\Domain\Dto\BuildSettings;
use Cbox\Cms\Core\Registry\Domain\Dto\ContributionOverride;
use Cbox\Cms\Core\Registry\Domain\Dto\ReplacementChoice;
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
function buildSettings(array $addons, array $panel = []): BuildSettings
{
    return BuildSettingsConfig::read(new Repository(['cbox-cms' => ['addons' => $addons, 'panel' => $panel]]));
}

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
