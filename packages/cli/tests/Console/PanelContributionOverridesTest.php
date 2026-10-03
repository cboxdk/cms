<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\ReplacementContribution;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Core\Registry\Boundary\BuildSettingsConfig;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fixtures\PanelAddon\ApprovalReason;
use Cbox\Cms\Core\Tests\Registry\PanelBuildWorld;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;

/*
 * The installation orders and enables the panel's contributions (PRD 13.4, 13.5):
 * cbox-cms.panel.contributions gives a contribution another priority or disables it, and
 * cbox-cms.panel.replacements picks the replacement that wins a key; cms:build compiles both. The
 * activation state, cbox-cms.panel.disabled, disables a contribution or a whole addon's panel UI
 * without a rebuild. cms:panel:fills shows each fill's order and enabled state and where each
 * comes from.
 */

/**
 * Builds the fixture addons' contributions with the installation's settings as cms:build reads
 * them from the configuration, and binds the registry for the commands.
 *
 * @param  array<string, mixed>  $panel  cbox-cms.panel
 */
function overriddenPanel(array $panel): CompiledRegistry
{
    $config = app(Repository::class);
    $config->set('cbox-cms.addons.allowed', [PanelBuildWorld::ADDON, PanelBuildWorld::STAMPS]);
    $config->set('cbox-cms.panel', $panel);

    $registry = PanelBuildWorld::build(
        PanelBuildWorld::addons([
            PanelBuildWorld::manifest([
                new SlotFill(new ContributionId('approvals.zeta'), 'notes.legacy@1', priority: 300),
                new SlotFill(new ContributionId('approvals.alpha'), 'notes.legacy@1', priority: 300),
                new ReplacementContribution(new ContributionId('approvals.reason-input'), 'notes.form.any@1', ApprovalReason::class),
            ]),
            PanelBuildWorld::manifest([
                new SlotFill(new ContributionId('stamps.mark'), 'notes.legacy@1', priority: 100),
                new SlotFill(new ContributionId('stamps.seal'), 'notes.legacy@1', priority: 200),
                new ReplacementContribution(new ContributionId('stamps.reason-input'), 'notes.form.any@1', ApprovalReason::class),
            ], [], namespace: 'stamps', package: PanelBuildWorld::STAMPS),
        ]),
        BuildSettingsConfig::read($config),
    );
    $cache = new FakeRegistryCache;
    $cache->write($registry);
    app()->instance(RegistryCache::class, $cache);
    app()->instance(CompiledRegistry::class, $registry);

    return $registry;
}

/**
 * @return array{int, string}
 */
function overriddenFills(string $point): array
{
    $status = Artisan::call('cms:panel:fills', ['point' => $point]);

    return [$status, Artisan::output()];
}

it('shows the order and enabled state of the addons when the installation sets nothing', function (): void {
    overriddenPanel(['contributions' => [], 'replacements' => ['notes.form.any@1' => [ApprovalReason::class => 'approvals.reason-input']], 'disabled' => ['addons' => [], 'contributions' => []]]);

    [$status, $output] = overriddenFills('notes.legacy@1');

    expect($status)->toBe(0)
        ->and($output)->toBe(
            "notes.legacy@1: 4 contributions, in the order the host renders them\n"
            ."  1. stamps.mark  slot  acme/cms-stamps, addon stamps\n"
            ."     priority 100 from the addon, enabled\n"
            ."  2. stamps.seal  slot  acme/cms-stamps, addon stamps\n"
            ."     priority 200 from the addon, enabled\n"
            ."  3. approvals.alpha  slot  acme/cms-approvals, addon approvals\n"
            ."     priority 300 from the addon, enabled\n"
            ."  4. approvals.zeta  slot  acme/cms-approvals, addon approvals\n"
            ."     priority 300 from the addon, enabled\n",
        );
});

it('shows a contribution the installation reorders, one it disables and the replacement it chooses', function (): void {
    overriddenPanel([
        'contributions' => [
            'notes.legacy@1' => [
                'approvals.zeta' => ['priority' => 10],
                'stamps.mark' => ['enabled' => false],
            ],
        ],
        'replacements' => ['notes.form.any@1' => [ApprovalReason::class => 'stamps.reason-input']],
        'disabled' => ['addons' => [], 'contributions' => []],
    ]);

    [$legacyStatus, $legacy] = overriddenFills('notes.legacy@1');
    [$anyStatus, $any] = overriddenFills('notes.form.any@1');

    expect($legacyStatus)->toBe(0)
        ->and($legacy)->toBe(
            "notes.legacy@1: 4 contributions, in the order the host renders them\n"
            ."  1. approvals.zeta  slot  acme/cms-approvals, addon approvals\n"
            ."     priority 10 from the installation, enabled\n"
            ."  2. stamps.mark  slot  acme/cms-stamps, addon stamps\n"
            ."     priority 100 from the addon, disabled by the installation\n"
            ."  3. stamps.seal  slot  acme/cms-stamps, addon stamps\n"
            ."     priority 200 from the addon, enabled\n"
            ."  4. approvals.alpha  slot  acme/cms-approvals, addon approvals\n"
            ."     priority 300 from the addon, enabled\n",
        )
        ->and($anyStatus)->toBe(0)
        ->and($any)->toBe(
            "notes.form.any@1: 2 contributions, in the order the host renders them\n"
            .'  1. approvals.reason-input  replacement  acme/cms-approvals, addon approvals, replaces '.ApprovalReason::class."\n"
            .'     priority 1000 from the addon, passed over by the installation for '.ApprovalReason::class."\n"
            .'  2. stamps.reason-input  replacement  acme/cms-stamps, addon stamps, replaces '.ApprovalReason::class."\n"
            .'     priority 1000 from the addon, chosen by the installation for '.ApprovalReason::class."\n",
        );
});

it('disables a contribution and a whole addon by the activation state, without a rebuild', function (): void {
    overriddenPanel(['contributions' => [], 'replacements' => ['notes.form.any@1' => [ApprovalReason::class => 'approvals.reason-input']], 'disabled' => ['addons' => [], 'contributions' => []]]);
    app(Repository::class)->set('cbox-cms.panel.disabled', ['addons' => ['stamps'], 'contributions' => ['approvals.alpha']]);

    [$status, $output] = overriddenFills('notes.legacy@1');

    expect($status)->toBe(0)
        ->and($output)->toBe(
            "notes.legacy@1: 4 contributions, in the order the host renders them\n"
            ."  1. stamps.mark  slot  acme/cms-stamps, addon stamps\n"
            ."     priority 100 from the addon, disabled by the activation state\n"
            ."  2. stamps.seal  slot  acme/cms-stamps, addon stamps\n"
            ."     priority 200 from the addon, disabled by the activation state\n"
            ."  3. approvals.alpha  slot  acme/cms-approvals, addon approvals\n"
            ."     priority 300 from the addon, disabled by the activation state\n"
            ."  4. approvals.zeta  slot  acme/cms-approvals, addon approvals\n"
            ."     priority 300 from the addon, enabled\n",
        );
});

it('gives each fill\'s sources as JSON', function (): void {
    overriddenPanel([
        'contributions' => ['notes.legacy@1' => ['approvals.zeta' => ['priority' => 10, 'enabled' => false]]],
        'replacements' => ['notes.form.any@1' => [ApprovalReason::class => 'approvals.reason-input']],
        'disabled' => ['addons' => [], 'contributions' => ['stamps.seal']],
    ]);

    Artisan::call('cms:panel:fills', ['point' => 'notes.legacy@1', '--json' => true]);
    $document = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    expect($document)->toBeArray();
    $fills = is_array($document) && is_array($document['fills'] ?? null) ? $document['fills'] : [];

    expect(array_map(static fn (mixed $fill): array => is_array($fill) ? [$fill['contribution'], $fill['priority'], $fill['ordering'], $fill['enabled'], $fill['enabling']] : [], $fills))->toBe([
        ['approvals.zeta', 10, 'installation', false, 'installation'],
        ['stamps.mark', 100, 'addon', true, 'addon'],
        ['stamps.seal', 200, 'addon', false, 'activation'],
        ['approvals.alpha', 300, 'addon', true, 'addon'],
    ]);
});

it('exits 78 when the activation state is not of its form', function (): void {
    overriddenPanel(['contributions' => [], 'replacements' => ['notes.form.any@1' => [ApprovalReason::class => 'approvals.reason-input']], 'disabled' => ['addons' => [], 'contributions' => []]]);
    app(Repository::class)->set('cbox-cms.panel.disabled', ['contributions' => 'stamps.seal']);

    $status = Artisan::call('cms:panel:fills', ['point' => 'notes.legacy@1']);

    expect($status)->toBe(78);
});
