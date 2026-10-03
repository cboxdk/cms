<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Doctor;

use Cbox\Cms\Contracts\Doctor\CheckStatus;
use Cbox\Cms\Contracts\Doctor\FailureKind;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Doctor\Boundary\DoctorConfig;
use Cbox\Cms\Identity\IdentityServiceProvider;
use Cbox\Cms\Panel\Branding\Boundary\BrandingConfig;
use Cbox\Cms\Panel\Doctor\Domain\Checks\BrandingCheck;
use Cbox\Cms\Panel\Tests\Branding\BrandFixtures;
use Illuminate\Contracts\Config\Repository;

/*
 * cms:doctor's panel.branding (PRD 13.4): the panel module adds it after the identity module's
 * checks, it fails with its own code, doctor_panel_branding_invalid, for each way the branding
 * cannot be used, naming the key, and it does not block: the panel shows Cbox CMS meanwhile.
 */

/**
 * Runs the test with a scratch application of brand files, removed afterwards.
 *
 * @param  callable(BrandFixtures): void  $test
 */
function withBrandFiles(callable $test): void
{
    $files = new BrandFixtures;

    try {
        $test($files);
    } finally {
        $files->remove();
    }
}

it('is one of the doctor\'s checks, after the identity module\'s', function (): void {
    $checks = app(Repository::class)->get(DoctorConfig::CONFIG_KEY.'.'.DoctorConfig::CHECKS);

    expect($checks)->toBeArray()
        ->and(array_slice(is_array($checks) ? $checks : [], 0, count(IdentityServiceProvider::DOCTOR_CHECKS) + 1))->toBe([...IdentityServiceProvider::DOCTOR_CHECKS, BrandingCheck::class]);
});

it('fails with doctor_panel_branding_invalid for a branding cms:doctor cannot use, naming the key', function (array $setting, string $key): void {
    withBrandFiles(static function (BrandFixtures $files) use ($setting, $key): void {
        app(Repository::class)->set(BrandingConfig::KEY, ['root' => $files->root, ...$setting]);
        $result = app(BrandingCheck::class)->run();

        expect($result->status)->toBe(CheckStatus::Fail)
            ->and($result->code)->toBe(BrandingCheck::CODE)
            ->and(ErrorCode::tryFrom((string) $result->code))->toBe(ErrorCode::DoctorPanelBrandingInvalid)
            ->and($result->failure)->toBe(FailureKind::Violation)
            ->and($result->blocking)->toBeFalse()
            ->and($result->cause)->toContain($key);
    });
})->with([
    'a missing alternative text' => [['logo' => ['light' => 'brand/logo.svg', 'dark' => 'brand/logo-dark.svg']], 'cbox-cms.panel.branding.logo.alt'],
    'a logo that is not a readable SVG or PNG' => [['logo' => ['light' => 'brand/logo.txt', 'dark' => 'brand/logo-dark.svg', 'alt' => 'Skovbo']], 'cbox-cms.panel.branding.logo.light'],
    'a logo outside the application' => [['favicon' => '/etc/hosts'], 'cbox-cms.panel.branding.favicon'],
    'a name over 60 characters' => [['name' => str_repeat('n', 61)], 'cbox-cms.panel.branding.name'],
]);

it('passes a branding it can use, and no branding', function (): void {
    withBrandFiles(static function (BrandFixtures $files): void {
        app(Repository::class)->set(BrandingConfig::KEY, ['root' => $files->root, 'name' => 'Skovbo Content', 'logo' => ['light' => 'brand/logo.svg', 'dark' => 'brand/logo-dark.svg', 'alt' => 'Skovbo']]);

        expect(app(BrandingCheck::class)->run()->status)->toBe(CheckStatus::Pass);

        app(Repository::class)->set(BrandingConfig::KEY);

        expect(app(BrandingCheck::class)->run()->explanation)->toBe('The panel has no branding and shows Cbox CMS.');
    });
});
