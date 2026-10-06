<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Pest\Browser\Api\PendingAwaitablePage;

/*
 * The command palette in Chromium (GUARDRAILS 8 and 9, PRD 13.2, 13.4), against the build
 * `composer panel:build` writes, this checkout's test database and Valkey: a member of staff whose
 * role may run role.create and grant.list on the site's root, written by the testkit's fixture
 * writers because the grant commands come in a later task, signs in and opens the palette with the
 * keyboard from the start page. The palette lists the pages they may open and the commands they may
 * run, as action.list decided them on the server: the who-am-I page and "Create a role", and not a
 * command their role does not name. Typing filters the entries; Enter on the who-am-I entry lands
 * on the page, and Enter on a command entry opens the command's form page below the Inertia
 * profile's address. Escape closes the palette and gives the focus back. A member of staff without
 * any grant gets the pages alone. Every state makes the shared page assertions, the open palette at
 * the three widths of a phone, a tablet and a desktop: the translated texts, an empty console, no
 * script error, no axe finding at any impact, every WCAG 2.2 AA rule, and no policy violation.
 *
 * With CMS_DOCS_SCREENSHOTS=1 the open palette is also captured into docs/screenshots/palette.png
 * and palette-mobile.png, the images docs/addons/command-palette.md shows.
 */

const PALETTE_EMAIL = 'ida.lund@example.com';

const PALETTE_NAME = 'Ida Lund';

const PALETTE_PASSWORD = 'correct horse battery staple';

const PALETTE_ROLE = 'paletteadmin';

/** The widths the open palette is checked at: a phone, a tablet and a desktop. */
const PALETTE_WIDTHS = [390, 820, 1440];

/**
 * Signs the member of staff in and lands on the start page.
 */
function signInToPalette(string $email): PendingAwaitablePage
{
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    $page->type('email', $email)
        ->type('password', PALETTE_PASSWORD)
        ->click('button[type="submit"]');
    $page->assertPathIs('/cms');

    return $page;
}

/**
 * Opens the palette with Ctrl+K and waits for its search field to take the focus.
 */
function openPalette(PendingAwaitablePage $page): void
{
    $page->keys('body:first-of-type', 'Control+k');
    $page->assertVisible('[role="dialog"]');

    expect(PanelProbe::eventually($page, 'document.activeElement?.matches(\'input[type="search"]\') === true'))->toBeTrue();
}

/**
 * The texts of the palette's options, in order.
 *
 * @return list<string>
 */
function paletteOptions(PendingAwaitablePage $page): array
{
    $options = $page->script('[...document.querySelectorAll(\'[role="dialog"] [role="option"] [class~="cms-palette__label"]\')].map((label) => label.textContent)');

    return is_array($options) ? array_values(array_map(strval(...), array_filter($options, is_string(...)))) : [];
}

/**
 * Saves the page as docs/screenshots/<key>.png when CMS_DOCS_SCREENSHOTS is set.
 */
function capturePaletteScreenshot(PendingAwaitablePage $page, string $key, int $width, int $height): void
{
    if (getenv('CMS_DOCS_SCREENSHOTS') !== '1') {
        return;
    }

    $page->resize($width, $height)->screenshot(false, 'docs-'.$key);
    copy(Codebase::root().'/tests/Browser/Screenshots/docs-'.$key.'.png', Codebase::root().'/docs/screenshots/'.$key.'.png');
}

beforeEach(function (): void {
    $clock = new FakeClock;
    $ids = new FakeIdGenerator(seed: 19, clock: $clock);
    $connections = app(ConnectionResolverInterface::class);
    $identity = new PostgresIdentitySeeder($connections, $clock, $ids);
    $access = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, $ids);
    $root = new PostgresStructureFixtures($connections, $clock, $ids)->site('palette', [new Locale('da')])->root->id;
    $actor = $identity->addActor(ActorClass::Staff, ActorState::Active)->id;

    // The grant commands come in a later task, so the profile and the grant are written by the
    // testkit's fixture writers as the owner role: a role that may create roles and list grants.
    $identity->addProfile($actor, new ActorProfile(new DisplayName(PALETTE_NAME), new EmailAddress(PALETTE_EMAIL)));
    $access->grant($actor, $access->role(PALETTE_ROLE, ClassificationAccess::Internal, [new CommandName('role.create'), new CommandName('grant.list')]), $root);
    app(LocalCredentialStore::class)->bind($actor, new LoginIdentifier(PALETTE_EMAIL), app(PasswordHasher::class)->hash(new Password(PALETTE_PASSWORD)));
});

it('opens with the keyboard, lists the pages and commands the person may use, filters, and lands on the who-am-I page with Enter', function (): void {
    $page = signInToPalette(PALETTE_EMAIL);

    PanelPage::assertPage($page, ['panel.home.body', 'panel.palette.open']);
    openPalette($page);

    expect(paletteOptions($page))->toBe([PanelPage::text('panel.nav.account_me'), PanelPage::text('panel.action.role.create.title')]);

    foreach (PALETTE_WIDTHS as $width) {
        $page->resize($width, 820);
        PanelPage::assertPage($page, ['panel.palette.pages', 'panel.palette.commands', 'panel.nav.account_me', 'panel.action.role.create.title', 'panel.action.role.create.description']);
    }

    capturePaletteScreenshot($page, 'palette', 1024, 720);
    capturePaletteScreenshot($page, 'palette-mobile', 390, 760);
    $page->resize(1440, 900);

    $page->type('input[type="search"]', 'who');

    expect(PanelProbe::eventually($page, 'document.querySelectorAll(\'[role="dialog"] [role="option"]\').length === 1'))->toBeTrue()
        ->and(paletteOptions($page))->toBe([PanelPage::text('panel.nav.account_me')]);

    $page->keys('input[type="search"]', 'Enter');

    $page->assertPathIs('/cms/account/me')
        ->assertMissing('[role="dialog"]');
    PanelPage::assertPage($page, ['panel.account_me.title', 'panel.account_me.profile']);
    $page->assertSee(PALETTE_NAME)->assertSee(PALETTE_ROLE);
});

it('opens a command s form page with Enter on its entry, found by the command s name too', function (): void {
    $page = signInToPalette(PALETTE_EMAIL);

    openPalette($page);
    $page->type('input[type="search"]', 'role.cre');

    expect(PanelProbe::eventually($page, 'document.querySelectorAll(\'[role="dialog"] [role="option"]\').length === 1'))->toBeTrue()
        ->and(paletteOptions($page))->toBe([PanelPage::text('panel.action.role.create.title')]);

    $page->keys('input[type="search"]', 'Enter');

    $page->assertPathIs('/cms/commands/role.create/v1')
        ->assertMissing('[role="dialog"]');
    PanelPage::assertPage($page, []);
});

it('closes with Escape and gives the focus back to its button', function (): void {
    $page = signInToPalette(PALETTE_EMAIL);

    $page->click('button:has-text("'.PanelPage::text('panel.palette.open').'")');
    $page->assertVisible('[role="dialog"]');
    $page->keys('input[type="search"]', 'Escape');

    $page->assertMissing('[role="dialog"]')->assertPathIs('/cms');
    expect(PanelProbe::eventually($page, 'document.activeElement?.textContent?.includes('.json_encode(PanelPage::text('panel.palette.open'), JSON_THROW_ON_ERROR).') === true'))->toBeTrue();
    PanelPage::assertPage($page, ['panel.home.body']);
});

it('gives a member of staff without any grant the pages alone', function (): void {
    $clock = new FakeClock;
    $identity = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 23, clock: $clock));
    $actor = $identity->addActor(ActorClass::Staff, ActorState::Active)->id;
    $email = 'per.holt@example.com';

    $identity->addProfile($actor, new ActorProfile(new DisplayName('Per Holt'), new EmailAddress($email)));
    app(LocalCredentialStore::class)->bind($actor, new LoginIdentifier($email), app(PasswordHasher::class)->hash(new Password(PALETTE_PASSWORD)));

    $page = signInToPalette($email);
    openPalette($page);

    expect(paletteOptions($page))->toBe([PanelPage::text('panel.nav.account_me')]);
    PanelPage::assertPage($page, ['panel.palette.pages', 'panel.nav.account_me']);
    $page->assertDontSee(PanelPage::text('panel.palette.commands'))
        ->assertDontSee(PanelPage::text('panel.action.role.create.title'));
});
