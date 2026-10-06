<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Panel\Branding\Boundary\BrandingConfig;
use Cbox\Cms\Panel\Branding\Domain\Dto\Branding;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Api\PendingAwaitablePage;

/*
 * The installation's brand in the panel, in Chromium (PRD 13.4, Sylvester, 2 October 2026): with
 * cbox-cms.panel.branding set to the workbench's fixtures, the login page and the shell's header
 * show the product name and the logo of the colour mode, whose alternative text is announced,
 * the document's title ends with the name and the favicon is the installation's, served from the
 * panel's own origin under the policy; without branding the panel shows Cbox CMS. Every page makes
 * the shared assertions, axe included.
 *
 * With CMS_DOCS_SCREENSHOTS=1 the branded login page and shell are also captured into
 * docs/screenshots, the images docs/developers/panel-branding.md shows.
 */

const BRAND_EMAIL = 'ines.lund@example.com';

const BRAND_PASSWORD = 'branded panel pass phrase';

const BRAND_NAME = 'Skovbo Content';

const BRAND_ALT = 'Skovbo';

beforeEach(function (): void {
    $clock = new FakeClock;
    $actor = new PostgresIdentitySeeder(app(DatabaseManager::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff, ActorState::Active);

    app(LocalCredentialStore::class)->bind($actor->id, new LoginIdentifier(BRAND_EMAIL), app(PasswordHasher::class)->hash(new Password(BRAND_PASSWORD)));
});

/**
 * Sets the installation's brand to the workbench's fixtures, as an application sets it.
 */
function brandPanel(): void
{
    app(Repository::class)->set(BrandingConfig::KEY, [
        'root' => Codebase::root().'/workbench',
        'name' => BRAND_NAME,
        'logo' => ['light' => 'resources/brand/logo-light.svg', 'dark' => 'resources/brand/logo-dark.svg', 'alt' => BRAND_ALT],
        'favicon' => 'resources/brand/favicon.png',
    ]);
    app()->forgetInstance(Branding::class);
}

/**
 * The images of the page a reader sees, each as its alternative text and the path of its address,
 * and whether it loaded. A hidden image is not in the accessibility tree, so these are the ones a
 * screen reader announces.
 *
 * @return list<array<array-key, mixed>> each with alt, path and loaded
 */
function shownImages(AwaitableWebpage|PendingAwaitablePage $page): array
{
    $images = $page->script(<<<'JS'
        () => [...document.querySelectorAll('img')]
            .filter((image) => getComputedStyle(image).display !== 'none' && image.getClientRects().length > 0)
            .map((image) => ({ alt: image.alt, path: new URL(image.src).pathname, loaded: image.complete && image.naturalWidth > 0 }))
        JS);

    return is_array($images) ? array_values(array_filter($images, is_array(...))) : [];
}

/**
 * The favicon's path and what the panel answers for it: its status and content type.
 *
 * @return array{path: string, status: int, type: string}|array<array-key, mixed>
 */
function favicon(AwaitableWebpage|PendingAwaitablePage $page): array
{
    $favicon = $page->script(<<<'JS'
        async () => {
            const link = document.querySelector('link[rel="icon"]');
            const response = await fetch(link.href);

            return { path: new URL(link.href).pathname, status: response.status, type: response.headers.get('content-type') };
        }
        JS);

    return is_array($favicon) ? $favicon : [];
}

/**
 * Saves the page as docs/screenshots/<key>.png when CMS_DOCS_SCREENSHOTS is set.
 */
function captureForDocs(AwaitableWebpage|PendingAwaitablePage $page, string $key): void
{
    if (getenv('CMS_DOCS_SCREENSHOTS') !== '1') {
        return;
    }

    $page->resize(1024, 640)->screenshot(false, 'docs-'.$key);
    copy(Codebase::root().'/tests/Browser/Screenshots/docs-'.$key.'.png', Codebase::root().'/docs/screenshots/'.$key.'.png');
}

it('shows the installation\'s name, logo and favicon on the login page and in the title', function (): void {
    brandPanel();

    $page = visit('/cms/login')->inLightMode();

    PanelPage::assertPage($page, ['panel.login.title']);
    $page->assertSee(BRAND_NAME)
        ->assertTitle(PanelPage::text('panel.title', ['page' => PanelPage::text('panel.login.title'), 'name' => BRAND_NAME]));

    $images = shownImages($page);

    expect($images)->toHaveCount(1)
        ->and($images[0]['alt'] ?? null)->toBe(BRAND_ALT)
        ->and($images[0]['path'] ?? '')->toMatch('~\A/cms/brand/logo-light-[0-9a-f]{16}\.svg\z~')
        ->and($images[0]['loaded'] ?? false)->toBeTrue()
        ->and(favicon($page))->toMatchArray(['status' => 200, 'type' => 'image/png'])
        ->and(favicon($page)['path'] ?? '')->toMatch('~\A/cms/brand/favicon-[0-9a-f]{16}\.png\z~');

    captureForDocs($page, 'branding-login');
});

it('shows the dark logo in the dark mode, with the same alternative text', function (): void {
    brandPanel();

    $page = visit('/cms/login')->inDarkMode();

    PanelPage::assertPage($page, ['panel.login.title']);
    $images = shownImages($page);

    expect($images)->toHaveCount(1)
        ->and($images[0]['alt'] ?? null)->toBe(BRAND_ALT)
        ->and($images[0]['path'] ?? '')->toMatch('~\A/cms/brand/logo-dark-[0-9a-f]{16}\.svg\z~')
        ->and($images[0]['loaded'] ?? false)->toBeTrue();
});

it('shows the installation\'s name and logo in the shell\'s header after signing in', function (): void {
    brandPanel();

    $page = visit('/cms/login')->inLightMode();
    $page->type('email', BRAND_EMAIL)
        ->type('password', BRAND_PASSWORD)
        ->click('button[type="submit"]');

    PanelPage::assertPage($page, ['panel.home.body', 'panel.home.sign_out']);
    $page->assertPathIs('/cms')
        ->assertTitle(PanelPage::text('panel.title', ['page' => PanelPage::text('panel.home.title'), 'name' => BRAND_NAME]));

    // The header holds the brand and, beside it, the command palette's button, so the name is the brand's.
    expect($page->script('document.querySelector("header .cms-brand").textContent'))->toBe(BRAND_NAME)
        ->and(array_column(shownImages($page), 'alt'))->toBe([BRAND_ALT]);

    captureForDocs($page, 'branding-shell');
});

it('shows Cbox CMS and no logo without branding', function (): void {
    $page = visit('/cms/login')->inLightMode();

    PanelPage::assertPage($page, ['panel.login.title', 'panel.name']);
    $page->assertTitle(PanelPage::text('panel.title', ['page' => PanelPage::text('panel.login.title'), 'name' => PanelPage::text('panel.name')]));

    expect(shownImages($page))->toBe([])
        ->and($page->script('document.querySelector(\'link[rel="icon"]\').getAttribute("href")'))->toBe('data:,')
        ->and($page->script('document.querySelector(\'meta[name="application-name"]\')'))->toBeNull();
});

it('shows Cbox CMS when the branding cannot be used, as cms:doctor reports it', function (): void {
    app(Repository::class)->set(BrandingConfig::KEY, ['name' => str_repeat('n', 61)]);
    app()->forgetInstance(Branding::class);

    $page = visit('/cms/login')->inLightMode();

    PanelPage::assertPage($page, ['panel.login.title', 'panel.name']);
    expect(shownImages($page))->toBe([]);
});
