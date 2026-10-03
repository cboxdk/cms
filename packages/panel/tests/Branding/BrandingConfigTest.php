<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Branding;

use Cbox\Cms\Panel\Branding\Boundary\BrandingConfig;
use Cbox\Cms\Panel\Branding\Domain\BrandImageType;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Panel\Branding\Domain\InvalidBranding;
use Illuminate\Config\Repository;

/*
 * The installation's brand as cbox-cms.panel.branding sets it (PRD 13.4, Sylvester, 2 October
 * 2026): a name of at most 60 characters, logos for the light and the dark mode with their
 * alternative text, the login page's image and a favicon, each file a readable SVG or PNG inside
 * the application, judged by its bytes. Every reason a branding cannot be used is listed.
 */

/**
 * @param  array<array-key, mixed>|null  $branding
 */
function branding(BrandFixtures $files, ?array $branding): Branding
{
    return BrandingConfig::read(new Repository(['cbox-cms' => ['panel' => ['branding' => $branding]]]), $files->root);
}

/**
 * @param  array<array-key, mixed>  $branding
 * @return list<string>
 */
function brandingReasons(BrandFixtures $files, array $branding): array
{
    try {
        branding($files, $branding);
    } catch (InvalidBranding $invalid) {
        return $invalid->reasons;
    }

    return [];
}

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

it('reads the name, the logos, the login page\'s image and the favicon', function (): void {
    withBrandFiles(static function (BrandFixtures $files): void {
        $brand = branding($files, [
            'name' => '  Skovbo Content ',
            'logo' => ['light' => 'brand/logo.svg', 'dark' => 'brand/logo-dark.svg', 'alt' => 'Skovbo'],
            'favicon' => $files->root.'/brand/favicon.png',
        ]);

        expect($brand->name())->toBe('Skovbo Content')
            ->and($brand->logo?->alt)->toBe('Skovbo')
            ->and($brand->logo?->light->type)->toBe(BrandImageType::Svg)
            ->and($brand->logo?->light->contents)->toBe(BrandFixtures::LIGHT)
            ->and($brand->logo?->light->name)->toMatch('~\Alogo-light-[0-9a-f]{16}\.svg\z~')
            ->and($brand->logo?->dark->name)->toMatch('~\Alogo-dark-[0-9a-f]{16}\.svg\z~')
            ->and($brand->loginImage())->toBe($brand->logo)
            ->and($brand->favicon?->type)->toBe(BrandImageType::Png)
            ->and($brand->favicon?->name)->toMatch('~\Afavicon-[0-9a-f]{16}\.png\z~')
            ->and($brand->file((string) $brand->favicon?->name))->toBe($brand->favicon)
            ->and($brand->file('favicon-0000000000000000.png'))->toBeNull();
    });
});

it('gives the login page an image of its own when the branding sets one', function (): void {
    withBrandFiles(static function (BrandFixtures $files): void {
        $brand = branding($files, [
            'logo' => ['light' => 'brand/logo.svg', 'dark' => 'brand/logo-dark.svg', 'alt' => 'Skovbo'],
            'login' => ['light' => 'brand/favicon.png', 'dark' => 'brand/favicon.png', 'alt' => 'Skovbo sign-in'],
        ]);

        expect($brand->loginImage()?->alt)->toBe('Skovbo sign-in')
            ->and($brand->loginImage()?->light->name)->toMatch('~\Alogin-light-~')
            ->and($brand->name())->toBe(Branding::DEFAULT_NAME);
    });
});

it('shows Cbox CMS without branding', function (?array $setting): void {
    withBrandFiles(static function (BrandFixtures $files) use ($setting): void {
        $brand = branding($files, $setting);

        expect($brand->name())->toBe('Cbox CMS')
            ->and([$brand->logo, $brand->login, $brand->favicon])->toBe([null, null, null]);
    });
})->with([
    'no setting' => [null],
    'the defaults' => [['root' => null, 'name' => null, 'logo' => null, 'login' => null, 'favicon' => null]],
]);

it('refuses a branding it cannot use, with every reason', function (array $setting, string $reason): void {
    withBrandFiles(static function (BrandFixtures $files) use ($setting, $reason): void {
        expect(implode("\n", brandingReasons($files, $setting)))->toContain($reason);
    });
})->with([
    'a name over 60 characters' => [['name' => str_repeat('n', 61)], 'cbox-cms.panel.branding.name must be the product name, 1 to 60 characters of text without control characters; it is "'.str_repeat('n', 61).'".'],
    'a name with a line break' => [['name' => "Skovbo\nContent"], 'cbox-cms.panel.branding.name must be the product name, 1 to 60 characters of text without control characters; it is "Skovbo'."\n".'Content".'],
    'a logo without its alternative text' => [['logo' => ['light' => 'brand/logo.svg', 'dark' => 'brand/logo-dark.svg']], 'cbox-cms.panel.branding.logo.alt must be the alternative text a screen reader announces for the image, 1 to 150 characters; it is missing.'],
    'a logo with an empty alternative text' => [['logo' => ['light' => 'brand/logo.svg', 'dark' => 'brand/logo-dark.svg', 'alt' => ' ']], 'cbox-cms.panel.branding.logo.alt must be the alternative text a screen reader announces for the image, 1 to 150 characters; it is " ".'],
    'a logo that is not an SVG or PNG' => [['logo' => ['light' => 'brand/logo.txt', 'dark' => 'brand/logo-dark.svg', 'alt' => 'Skovbo']], 'cbox-cms.panel.branding.logo.light names "brand/logo.txt", which is not a readable SVG or PNG of at most 512 KiB, or is an SVG with a script, an event handler or a foreignObject.'],
    'a logo that does not exist' => [['logo' => ['light' => 'brand/none.svg', 'dark' => 'brand/logo-dark.svg', 'alt' => 'Skovbo']], 'cbox-cms.panel.branding.logo.light names "brand/none.svg", which is not a file inside the application ('],
    'a logo outside the application' => [['favicon' => '/etc/hosts'], 'cbox-cms.panel.branding.favicon names "/etc/hosts", which is not a file inside the application ('],
    'a logo that climbs out of the application' => [['favicon' => '../../../etc/hosts'], 'cbox-cms.panel.branding.favicon names "../../../etc/hosts", which is not a file inside the application ('],
    'a logo through a stream wrapper' => [['favicon' => 'ftp://example.com/logo.png'], 'cbox-cms.panel.branding.favicon names "ftp://example.com/logo.png", which is not a file inside the application ('],
    'an SVG with a script' => [['favicon' => 'brand/script.svg'], 'cbox-cms.panel.branding.favicon names "brand/script.svg", which is not a readable SVG or PNG of at most 512 KiB, or is an SVG with a script, an event handler or a foreignObject.'],
    'an SVG with an event handler' => [['favicon' => 'brand/handler.svg'], 'cbox-cms.panel.branding.favicon names "brand/handler.svg", which is not a readable SVG or PNG of at most 512 KiB, or is an SVG with a script, an event handler or a foreignObject.'],
    'a file over 512 KiB' => [['favicon' => 'brand/huge.png'], 'cbox-cms.panel.branding.favicon names "brand/huge.png", which is not a readable SVG or PNG of at most 512 KiB, or is an SVG with a script, an event handler or a foreignObject.'],
    'an unknown key' => [['colour' => '#000000'], 'cbox-cms.panel.branding.colour is not a key of the branding; the keys are favicon, login, logo, name, root.'],
    'a logo that is a list' => [['logo' => ['brand/logo.svg']], 'cbox-cms.panel.branding.logo must be a map with light, dark and alt: a file for each mode and the text a screen reader announces; it is array.'],
]);

it('lists every reason at once', function (): void {
    withBrandFiles(static function (BrandFixtures $files): void {
        expect(brandingReasons($files, ['name' => '', 'logo' => ['light' => 'brand/logo.txt', 'dark' => 'brand/logo-dark.svg']]))->toHaveCount(3);
    });
});

it('refuses a branding that is not a map', function (): void {
    expect(static fn (): Branding => BrandingConfig::read(new Repository(['cbox-cms' => ['panel' => ['branding' => 'Skovbo']]]), '/'))
        ->toThrow(InvalidBranding::class, 'cbox-cms.panel.branding must be a map with the keys favicon, login, logo, name, root; it is string.');
});
