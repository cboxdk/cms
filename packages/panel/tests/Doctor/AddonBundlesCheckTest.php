<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Doctor;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Core\Doctor\Domain\Checks\RegistryCacheCheck;
use Cbox\Cms\Identity\IdentityServiceProvider;
use Cbox\Cms\Panel\Doctor\Boundary\DiskAddonBundlesProbe;
use Cbox\Cms\Panel\Doctor\Domain\Checks\AddonBundlesCheck;
use Cbox\Cms\Panel\Doctor\Domain\Checks\BrandingCheck;
use Cbox\Cms\Panel\Doctor\Domain\Checks\DevServerCheck;
use Cbox\Cms\Panel\Doctor\Domain\Dto\BundleState;
use Cbox\Cms\Panel\Doctor\Domain\Probes\AddonBundlesProbe;
use Cbox\Cms\Panel\Domain\Dto\BundleDirectories;
use Cbox\Cms\Panel\Tests\Addons\AddonBundleWorld;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\Doctor\Fakes\FakeAddonBundlesProbe;
use Illuminate\Contracts\Config\Repository;

/*
 * cms:doctor's panel.addons (PRD 13.4): the panel module adds it after panel.branding, it fails
 * with doctor_panel_addons_changed when a file of an addon's bundle on disk is not what cms:build
 * compiled, naming the addon and the file, and it does not block. The probe on disk reads the
 * bundle the way the panel serves it.
 */

it('is one of the doctor\'s checks, after the identity module\'s and panel.branding, with panel.dev_server', function (): void {
    $checks = app(Repository::class)->get(DoctorConfig::CONFIG_KEY.'.'.DoctorConfig::CHECKS);

    expect($checks)->toBeArray()
        ->and(array_slice(is_array($checks) ? $checks : [], 0, count(IdentityServiceProvider::DOCTOR_CHECKS) + 3))
        ->toBe([...IdentityServiceProvider::DOCTOR_CHECKS, BrandingCheck::class, AddonBundlesCheck::class, DevServerCheck::class]);
});

it('passes when no addon ships panel UI, and when every bundle is what cms:build compiled', function (): void {
    $check = new AddonBundlesCheck(new FakeAddonBundlesProbe);

    expect($check->run()->status)->toBe(CheckStatus::Pass)
        ->and($check->run()->explanation)->toBe('No installed addon ships panel UI.')
        ->and($check->blocking())->toBeFalse()
        ->and($check->requires())->toHaveCount(1)
        ->and($check->requires()[0]->value)->toBe(RegistryCacheCheck::ID);

    $result = new AddonBundlesCheck(new FakeAddonBundlesProbe([new BundleState(new AddonNamespace('approvals')), new BundleState(new AddonNamespace('tally'))]))->run();

    expect($result->status)->toBe(CheckStatus::Pass)
        ->and($result->explanation)->toBe('The panel bundle of 2 addons on disk are what cms:build compiled: approvals, tally.');
});

it('fails with doctor_panel_addons_changed, naming each addon and file, and does not block', function (): void {
    $result = new AddonBundlesCheck(new FakeAddonBundlesProbe([
        new BundleState(new AddonNamespace('approvals')),
        new BundleState(new AddonNamespace('tally'), ['the file assets/addon-1a2b3c.js has another SHA-384 than cms:build compiled']),
    ]))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->code)->toBe(AddonBundlesCheck::CODE)
        ->and(ErrorCode::tryFrom((string) $result->code))->toBe(ErrorCode::DoctorPanelAddonsChanged)
        ->and($result->failure)->toBe(FailureKind::Violation)
        ->and($result->blocking)->toBeFalse()
        ->and($result->explanation)->toContain('one addon')
        ->and($result->cause)->toBe('tally: the file assets/addon-1a2b3c.js has another SHA-384 than cms:build compiled')
        ->and($result->fix)->toContain('cms:build');
});

it('fails when the compiled registry cannot be read', function (): void {
    $result = new AddonBundlesCheck(new FakeAddonBundlesProbe(registryMissing: true))->run();

    expect($result->status)->toBe(CheckStatus::Fail)
        ->and($result->code)->toBe(AddonBundlesCheck::CODE)
        ->and($result->explanation)->toContain('cannot be read');
});

it('reads the bundles on disk against the registry, as the panel serves them', function (): void {
    $world = AddonBundleWorld::write();

    try {
        $cache = ContributionWorld::cache($world->registry);
        $probe = new DiskAddonBundlesProbe($cache, new BundleDirectories([AddonBundleWorld::NAMESPACE => $world->directory]));

        expect(array_map(static fn (BundleState $state): array => [$state->addon->value, $state->problems], $probe->bundles()))->toBe([['tally', []]]);

        $world->tamper(AddonBundleWorld::STYLE);
        unlink($world->directory.'/'.AddonBundleWorld::ENTRY);

        expect($probe->bundles()[0]->problems)->toBe([
            'the file assets/addon-1a2b3c.js is missing or unreadable',
            'the file assets/addon-4d5e6f.css has another SHA-384 than cms:build compiled',
        ]);

        $unlocated = new DiskAddonBundlesProbe($cache, BundleDirectories::none());

        expect($unlocated->bundles()[0]->problems)->toBe(['the process knows no directory for the bundle, because the addon\'s provider names none']);

        $world->bind(app());

        expect(app(AddonBundlesProbe::class))->toBeInstanceOf(DiskAddonBundlesProbe::class)
            ->and(app(AddonBundlesProbe::class)->bundles()[0]->problems)->not->toBe([]);
    } finally {
        $world->remove();
    }
});
