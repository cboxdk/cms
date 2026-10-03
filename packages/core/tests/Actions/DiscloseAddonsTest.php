<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Addons\AddonManifest;
use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Addons\CoreApiVersion;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\SlotFill;
use Cbox\Cms\Core\Registry\Actions\DiscloseAddons;
use Cbox\Cms\Core\Registry\Domain\Dto\AddonDisclosure;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\Dto\IssuedCommand;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Core\Tests\Registry\PanelBuildWorld;

/*
 * DiscloseAddons gives the install screen what each installed addon does (PRD 13.1, 13.4): its
 * capabilities (reads, the commands its panel UI issues, whether it ships a theme), its panel API
 * version, its experimental opt-ins and bundle, the points its contributions touch, the number of
 * its hooks and subscribers, and the trust statement of in-window addon UI.
 */

it('discloses each installed addon by namespace, with the points it touches and the trust statement of its UI', function (): void {
    $cache = new FakeRegistryCache;
    $cache->write(PanelBuildWorld::build(PanelBuildWorld::addons([
        PanelBuildWorld::manifest(PanelBuildWorld::everyKind()),
        PanelBuildWorld::manifest([new SlotFill(new ContributionId('stamps.mark'), 'notes.legacy@1')], [], namespace: 'stamps', package: PanelBuildWorld::STAMPS, bundle: PanelBuildWorld::BUNDLE),
    ])));

    $disclosed = new DiscloseAddons($cache)->disclose();
    [$approvals, $stamps] = $disclosed;

    expect(array_map(static fn (AddonDisclosure $disclosure): string => $disclosure->addon->namespace->value, $disclosed))->toBe(['approvals', 'stamps'])
        ->and($approvals->addon->reads)->toBe(ClassificationAccess::Internal)
        ->and(array_map(static fn (IssuedCommand $issued): string => $issued->command->toString(), $approvals->addon->issues))->toBe(['approvals.request@1'])
        ->and($approvals->addon->uiTheme)->toBeTrue()
        ->and(array_map(static fn (PointId $point): string => $point->toString(), $approvals->points))->toBe([
            'notes.detail.actions@1',
            'notes.detail.sections@1',
            'notes.form.checks@1',
            'notes.form.command@1',
            'notes.form.field@1',
            'notes.form.steps@1',
            'notes.form.submit@1',
            'notes.form.value@1',
            'notes.legacy@1',
            'notes.login.notice@1',
            'notes.nav@1',
            'notes.observe@1',
            'notes.page@1',
            'notes.palette@1',
        ])
        ->and(array_map(static fn (PointId $point): string => $point->toString(), $approvals->experimental))->toBe(['notes.detail.sections@1'])
        ->and($approvals->hooks)->toBe(3)
        ->and($approvals->subscribers)->toBe(0)
        ->and($approvals->trust())->toBe(AddonDisclosure::IN_WINDOW_TRUST)
        ->and(array_map(static fn (PointId $point): string => $point->toString(), $stamps->points))->toBe(['notes.legacy@1'])
        ->and($stamps->experimental)->toBe([]);
});

it('discloses an addon without panel UI without the trust statement of in-window UI, and no addon as none', function (): void {
    $cache = new FakeRegistryCache;
    $cache->write(PanelBuildWorld::build(PanelBuildWorld::addons([
        new AddonManifest(PanelBuildWorld::STAMPS, new AddonNamespace('stamps'), CoreApiVersion::current(), __DIR__),
    ])));
    $plain = new FakeRegistryCache;
    $plain->write(CompiledRegistry::empty());
    $stamps = new DiscloseAddons($cache)->disclose()[0];

    expect($stamps->addon->panel)->toBeNull()
        ->and($stamps->trust())->toBeNull()
        ->and($stamps->points)->toBe([])
        ->and($stamps->addon->reads)->toBe(ClassificationAccess::Public)
        ->and(new DiscloseAddons($plain)->disclose())->toBe([]);
});

it('refuses a registry cache that cannot be read', function (): void {
    $damaged = new FakeRegistryCache;
    $damaged->write(CompiledRegistry::empty());
    $damaged->damage();

    expect(fn (): mixed => new DiscloseAddons(new FakeRegistryCache)->disclose())->toThrow(RegistryCacheMissing::class)
        ->and(fn (): mixed => new DiscloseAddons($damaged)->disclose())->toThrow(MalformedRegistryCache::class);
});
