<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Valkey\ValkeyRun;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;

/*
 * Logging in to the panel and out again in Chromium (PRD 5.16, GUARDRAILS 6, 8 and 9), against the
 * build `composer panel:build` writes, this checkout's test database and Valkey: a member of staff
 * with a local account signs in with the email and password and lands on the start page, signs out
 * and is back on the login page with the reason; a wrong password shows the one generic refusal;
 * the whole login works with the keyboard alone; and the page is set in the Cbox typefaces, served
 * from the panel's own origin. Every page makes the shared assertions: its
 * translated text, an empty console, no script error, no axe finding and no policy violation.
 *
 * Laravel's session is in Valkey here, as docs/security/sessions.md has an application set it, so
 * the run's keys show that it holds no CMS session id.
 */

const PANEL_EMAIL = 'mette.holm@example.com';

const PANEL_PASSWORD = 'correct horse battery staple';

beforeEach(function (): void {
    app(Repository::class)->set('session.driver', 'redis');
    app(Repository::class)->set('session.connection', 'default');

    $clock = new FakeClock;
    $actor = new PostgresIdentitySeeder(app(DatabaseManager::class), $clock, new FakeIdGenerator(clock: $clock))->addActor(ActorClass::Staff, ActorState::Active);

    app(LocalCredentialStore::class)->bind($actor->id, new LoginIdentifier(PANEL_EMAIL), app(PasswordHasher::class)->hash(new Password(PANEL_PASSWORD)));
});

/**
 * The texts of the login page, with the message of the reason it was sent there, if any.
 *
 * @return list<string>
 */
function loginTexts(string ...$more): array
{
    return array_values(['panel.login.title', 'panel.login.description', 'panel.login.email', 'panel.login.password', ...$more]);
}

it('signs a member of staff in to the start page and out again to the login page', function (): void {
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    PanelPage::assertPage($page, loginTexts('panel.login.reason.required'));

    $page->type('email', PANEL_EMAIL)
        ->type('password', PANEL_PASSWORD)
        ->click('button[type="submit"]');

    PanelPage::assertPage($page, ['panel.home.body', 'panel.home.sign_out']);
    $page->assertPathIs('/cms');

    $stored = implode(' ', app(ValkeyRun::class)->keys());

    expect($stored)->toContain('cms:session:')
        ->and($stored)->not->toContain('cms_ss_');

    $page->click('button[type="submit"]');

    PanelPage::assertPage($page, loginTexts('panel.login.reason.signed_out'));
    $page->assertPathIs('/cms/login');

    expect(implode(' ', app(ValkeyRun::class)->keys()))->not->toContain('cms:session:');

    $page->navigate('/cms');
    $page->assertPathIs('/cms/login');
    PanelPage::assertPage($page, loginTexts('panel.login.reason.required'));
});

it('shows the one generic refusal for a wrong password and keeps the email', function (): void {
    $page = visit('/cms/login');

    PanelPage::assertPage($page, loginTexts());

    $page->type('email', PANEL_EMAIL)
        ->type('password', 'not the password at all')
        ->click('button[type="submit"]');

    PanelPage::assertPage($page, loginTexts('panel.login.failed'));
    $page->assertPathIs('/cms/login')
        ->assertValue('email', PANEL_EMAIL)
        ->assertValue('password', '');
});

it('sets the page in Plus Jakarta Sans and code in JetBrains Mono, loaded from the panel\'s own origin', function (): void {
    $page = visit('/cms/login')->inLightMode();

    PanelPage::assertPage($page, loginTexts());

    $fonts = $page->script(<<<'JS'
        (async () => {
            await Promise.all([document.fonts.load("1em 'Plus Jakarta Sans'"), document.fonts.load("1em 'JetBrains Mono'")]);
            await document.fonts.ready;
            const loaded = [...document.fonts].filter((face) => face.status === 'loaded').map((face) => face.family.replaceAll('"', ''));
            const files = performance.getEntriesByType('resource').map((entry) => new URL(entry.name)).filter((url) => url.pathname.endsWith('.woff2'));

            return {
                body: getComputedStyle(document.body).fontFamily,
                mono: getComputedStyle(document.documentElement).getPropertyValue('--cms-font-family-mono').trim(),
                loaded: [...new Set(loaded)].sort(),
                foreign: files.filter((url) => url.origin !== location.origin).length,
                files: files.length,
            };
        })()
        JS);

    $fonts = is_array($fonts) ? $fonts : [];

    expect($fonts['body'] ?? null)->toBeString()->toMatch('/\A["\']Plus Jakarta Sans["\'],/')
        ->and($fonts['mono'] ?? null)->toBeString()->toMatch('/\A["\']JetBrains Mono["\'],/')
        ->and($fonts['loaded'] ?? null)->toBe(['JetBrains Mono', 'Plus Jakarta Sans'])
        ->and($fonts['foreign'] ?? null)->toBe(0)
        ->and($fonts['files'] ?? null)->toBeInt()->toBeGreaterThan(0);
});

it('signs in with the keyboard alone and shows where the focus is', function (): void {
    $page = visit('/cms/login');

    PanelPage::assertPage($page, loginTexts());

    $page->keys('app', 'Tab');

    expect($page->script('document.activeElement.getAttribute("name")'))->toBe('email')
        ->and($page->script('getComputedStyle(document.activeElement).boxShadow'))->not->toBe('none');

    $page->keys('[name="email"]', str_split(PANEL_EMAIL))
        ->keys('[name="email"]', 'Tab');

    expect($page->script('document.activeElement.getAttribute("name")'))->toBe('password');

    $page->keys('[name="password"]', str_split(PANEL_PASSWORD))
        ->keys('[name="password"]', 'Enter');

    PanelPage::assertPage($page, ['panel.home.body', 'panel.home.sign_out']);
    $page->assertPathIs('/cms');

    $page->keys('app', 'Tab');

    expect($page->script('document.activeElement.textContent'))->toBe(PanelPage::text('panel.home.sign_out'));

    $page->keys('button[type="submit"]', 'Enter');

    PanelPage::assertPage($page, loginTexts('panel.login.reason.signed_out'));
});
