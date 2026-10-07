<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Panel\Tests\Access\AccessWorld;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use DateInterval;
use LogicException;
use Pest\Browser\Api\PendingAwaitablePage;

/*
 * Roles and grants in the panel, in Chromium (PRD 5.10, 13.4, GUARDRAILS 8 and 9), against the
 * build `composer panel:build` writes, this checkout's test database and Valkey, over AccessWorld,
 * written by the testkit's fixture writers: an administrator whose role may run the access
 * commands and queries, with personal classification access, a member of staff without a grant,
 * and the roles editor, publisher and admins; the commands run through the real pipeline, which
 * writes the changesets at the clock, so the partitions cover it. The administrator signs in, opens the roles and
 * grants pages from the navigation, assigns the non-administrative role editor to the other
 * member of staff on the site's root with the keyboard and the pickers, sees the grant in the
 * list and, signed in as that member of staff, on their who-am-I page, and revokes it; creates a
 * role and changes its permissions; and is refused by the escalation guard, with the code's
 * explanation in their language, when the role is administrative (step_up_required) or holds a
 * permission the administrator does not (grant_escalation_refused). A member of staff without the
 * permissions gets neither page in the navigation and, at the address, why. Every page and state
 * makes the shared assertions at the three widths of a phone, a tablet and a desktop: the
 * translated texts, an empty console, no script error, no axe finding at any impact, every WCAG
 * 2.2 AA rule, and no policy violation.
 *
 * With CMS_DOCS_SCREENSHOTS=1 the pages are also captured into docs/screenshots/access-roles.png,
 * access-roles-mobile.png, access-grants.png, access-grants-mobile.png and access-grant-assign.png,
 * the images docs/addons/panel-pages.md shows.
 */

const ACCESS_PASSWORD = 'correct horse battery staple';

/** The widths the pages are checked at: a phone, a tablet and a desktop. */
const ACCESS_WIDTHS = [390, 820, 1440];

/**
 * The world of the test, which beforeEach writes for each test.
 */
function accessWorld(?AccessWorld $world = null): AccessWorld
{
    /** @var AccessWorld|null $current */
    static $current = null;

    if ($world instanceof AccessWorld) {
        $current = $world;
    }

    return $current ?? throw new LogicException('No access world.');
}

/**
 * Signs the member of staff in and lands on the start page.
 */
function signInToAccess(string $email): PendingAwaitablePage
{
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    $page->type('email', $email)
        ->type('password', ACCESS_PASSWORD)
        ->click('button[type="submit"]');
    $page->assertPathIs('/cms');

    return $page;
}

/**
 * Signs out from a page behind the login, which lands on the login page.
 */
function signOutOfAccess(PendingAwaitablePage $page): void
{
    $page->click('main form button[type="submit"]:has-text("'.PanelPage::text('panel.home.sign_out').'")');
    $page->assertPathIs('/cms/login');
}

/**
 * Makes the shared assertions at the three widths, and leaves the page at a desktop width.
 *
 * @param  list<string>  $texts
 */
function assertAccessPage(PendingAwaitablePage $page, array $texts): void
{
    foreach (ACCESS_WIDTHS as $width) {
        $page->resize($width, 900);
        PanelPage::assertPage($page, $texts);
    }

    $page->resize(1440, 900);
}

/**
 * Saves the page as docs/screenshots/<key>.png when CMS_DOCS_SCREENSHOTS is set.
 */
function captureAccessScreenshot(PendingAwaitablePage $page, string $key, int $width, int $height): void
{
    if (getenv('CMS_DOCS_SCREENSHOTS') !== '1') {
        return;
    }

    $page->resize($width, $height)->screenshot(false, 'docs-'.$key);
    copy(Codebase::root().'/tests/Browser/Screenshots/docs-'.$key.'.png', Codebase::root().'/docs/screenshots/'.$key.'.png');
    $page->resize(1440, 900);
}

/**
 * Waits until the expression is true on the page: a command's answer arrives after the Inertia
 * profile has redirected back to the page, a frame or two later.
 */
function waitUntil(PendingAwaitablePage $page, string $expression): void
{
    expect(PanelProbe::eventually($page, $expression))->toBeTrue($expression);
}

/**
 * Waits until the pickers of the form to assign a grant have arrived.
 */
function waitForPickers(PendingAwaitablePage $page): void
{
    $page->assertVisible('[role="dialog"]');

    expect(PanelProbe::eventually($page, 'document.body.textContent?.includes('.json_encode(PanelPage::text('panel.grants.pickers_loading'), JSON_THROW_ON_ERROR).') === false'))->toBeTrue();
}

/**
 * Chooses the member of staff and the role in the form's comboboxes by typing and the arrow keys,
 * and the node in the picker's tree with the keyboard.
 */
function chooseGrant(PendingAwaitablePage $page, string $actor, string $role, string $node): void
{
    chooseOption($page, 1, $actor);
    chooseOption($page, 2, $role);
    $page->keys('[role="dialog"] form > :nth-child(3) button', 'Enter');
    $page->assertVisible('[role="treegrid"]');
    $page->keys('[role="treegrid"] [role="row"]:has-text("'.$node.'")', 'Space');
    $page->click('[role="dialog"]:has([role="treegrid"]) button:has-text("Choose")');
    $page->assertMissing('[role="treegrid"]');
}

/**
 * Chooses the first option of the form's combobox at the position by typing the text and waiting
 * until the list is filtered to it, since the arrow key chooses the first option the list holds
 * when it is pressed.
 */
function chooseOption(PendingAwaitablePage $page, int $position, string $text): void
{
    $input = '[role="dialog"] form > :nth-child('.$position.') input[role="combobox"]';

    $page->type($input, $text);
    waitUntil($page, 'document.querySelector(\'[role="listbox"] [role="option"]\')?.textContent?.toLowerCase().includes('.json_encode(strtolower($text), JSON_THROW_ON_ERROR).') === true');
    $page->keys($input, 'ArrowDown');
    $page->keys($input, 'Enter');
}

/**
 * The texts of the grants page.
 *
 * @return list<string>
 */
function grantsTexts(string ...$more): array
{
    return array_values(['panel.grants.title', 'panel.grants.description', 'panel.grants.column.actor', 'panel.grants.column.role', 'panel.grants.column.node', 'panel.grants.column.effect', 'panel.grants.column.locales', 'panel.home.sign_out', ...$more]);
}

/**
 * The texts of the roles page.
 *
 * @return list<string>
 */
function rolesTexts(string ...$more): array
{
    return array_values(['panel.roles.title', 'panel.roles.description', 'panel.roles.column.handle', 'panel.roles.column.ceiling', 'panel.roles.column.permissions', 'panel.home.sign_out', ...$more]);
}

beforeEach(function (): void {
    $world = accessWorld(new AccessWorld(app(), seed: 29, fixtureBuild: false));
    $hasher = app(PasswordHasher::class);

    app(PartitionFixtures::class)->coverClock(app(Clock::class), new DateInterval('P2D'));

    app(LocalCredentialStore::class)->bind($world->admin, new LoginIdentifier(AccessWorld::ADMIN_EMAIL), $hasher->hash(new Password(ACCESS_PASSWORD)));
    app(LocalCredentialStore::class)->bind($world->ole, new LoginIdentifier(AccessWorld::OLE_EMAIL), $hasher->hash(new Password(ACCESS_PASSWORD)));
});

it('assigns a non-administrative role to another member of staff with the keyboard and the pickers, sees the grant in the list and on their who-am-I page, and revokes it', function (): void {
    $world = accessWorld();
    $page = signInToAccess(AccessWorld::ADMIN_EMAIL);

    PanelPage::assertPage($page, ['panel.home.body', 'panel.nav.roles', 'panel.nav.grants']);
    $page->click('nav a:has-text("'.PanelPage::text('panel.nav.grants').'")');

    $page->assertPathIs('/cms/access/grants');
    assertAccessPage($page, grantsTexts('panel.grants.assign', 'panel.access.effect.allow', 'panel.access.every_locale'));
    $page->assertSee(AccessWorld::ADMIN_NAME)
        ->assertSee(AccessWorld::ADMIN_EMAIL)
        ->assertSee(AccessWorld::ADMIN_ROLE)
        ->assertDontSee(AccessWorld::OLE_NAME);

    expect($page->script('document.querySelector(\'nav a[aria-current="page"]\')?.textContent'))->toBe(PanelPage::text('panel.nav.grants'))
        ->and($page->script('document.title'))->toContain(PanelPage::text('panel.grants.title'));

    captureAccessScreenshot($page, 'access-grants', 1024, 720);
    captureAccessScreenshot($page, 'access-grants-mobile', 390, 760);

    // The form opens from its button with the keyboard, and reads the pickers as it opens.
    $page->keys('button:has-text("'.PanelPage::text('panel.grants.assign').'")', 'Enter');
    waitForPickers($page);
    assertAccessPage($page, ['panel.grants.assign_title', 'panel.grants.assign_description', 'panel.grants.actor', 'panel.grants.role', 'panel.grants.node', 'panel.grants.effect', 'panel.grants.locales', 'panel.grants.submit_assign']);
    captureAccessScreenshot($page, 'access-grant-assign', 1024, 720);

    chooseGrant($page, 'Ole', AccessWorld::EDITOR, 'access');
    $page->keys('[role="dialog"] form button[type="submit"]', 'Enter');
    waitUntil($page, 'document.querySelector(\'[role="dialog"]\') === null');

    $page->assertSee('Saved')
        ->assertSee(AccessWorld::OLE_NAME)
        ->assertSee(AccessWorld::OLE_EMAIL)
        ->assertSee(AccessWorld::EDITOR);
    assertAccessPage($page, grantsTexts('panel.grants.assigned'));

    // The grant is on the who-am-I page of the member of staff who got it.
    signOutOfAccess($page);
    $ole = signInToAccess(AccessWorld::OLE_EMAIL);
    $ole->navigate('/cms/account/me');

    $ole->assertPathIs('/cms/account/me');
    PanelPage::assertPage($ole, ['panel.account_me.grants', 'panel.account_me.effect.allow']);
    $ole->assertSee(AccessWorld::EDITOR)
        ->assertSee($world->root->toString())
        ->assertSee(PanelPage::text('panel.account_me.grant.every_locale'));
    signOutOfAccess($ole);

    // The administrator revokes it after a confirmation.
    $admin = signInToAccess(AccessWorld::ADMIN_EMAIL);
    $admin->navigate('/cms/access/grants');
    $admin->assertSee(AccessWorld::OLE_NAME);
    $admin->click('button[aria-label="'.PanelPage::text('panel.grants.row_actions', ['role' => AccessWorld::EDITOR, 'actor' => AccessWorld::OLE_NAME]).'"]');
    $admin->click('[role="menuitem"]:has-text("'.PanelPage::text('panel.grants.revoke').'")');
    $admin->assertVisible('[role="alertdialog"]');
    PanelPage::assertPage($admin, ['panel.grants.revoke_title', 'panel.grants.revoke_confirm', 'panel.access.cancel']);
    $admin->assertSee(AccessWorld::OLE_NAME);
    $admin->click('[role="alertdialog"] button:has-text("'.PanelPage::text('panel.grants.revoke_confirm').'")');
    waitUntil($admin, 'document.querySelector(\'[role="alertdialog"]\') === null');

    $admin->assertSee('Saved')
        ->assertDontSee(AccessWorld::OLE_NAME)
        ->assertSee(AccessWorld::ADMIN_NAME);
    assertAccessPage($admin, grantsTexts('panel.grants.revoked'));
});

it('shows a refusal by the escalation guard with its explanation, for an administrative role and for a permission the administrator does not hold', function (): void {
    $page = signInToAccess(AccessWorld::ADMIN_EMAIL);
    $page->navigate('/cms/access/grants');

    $page->keys('button:has-text("'.PanelPage::text('panel.grants.assign').'")', 'Enter');
    waitForPickers($page);
    chooseGrant($page, 'Ole', AccessWorld::ADMINS, 'access');
    $page->keys('[role="dialog"] form button[type="submit"]', 'Enter');
    waitUntil($page, 'document.querySelector(\'[role="dialog"] [role="alert"]\') !== null');

    assertAccessPage($page, ['panel.grants.assign_title', 'panel.problem.step_up_required']);
    $page->assertSee('step_up_required')
        ->assertSee('is administrative');
    $page->keys('[role="dialog"] form button[type="submit"]', 'Escape');
    waitUntil($page, 'document.querySelector(\'[role="dialog"]\') === null');

    $page->keys('button:has-text("'.PanelPage::text('panel.grants.assign').'")', 'Enter');
    waitForPickers($page);
    chooseGrant($page, 'Ole', AccessWorld::PUBLISHER, 'access');
    $page->keys('[role="dialog"] form button[type="submit"]', 'Enter');
    waitUntil($page, 'document.querySelector(\'[role="dialog"] [role="alert"]\') !== null');

    assertAccessPage($page, ['panel.grants.assign_title', 'panel.problem.grant_escalation_refused']);
    $page->assertSee('grant_escalation_refused')
        ->assertSee('entry.publish');
});

it('lists the roles, creates a role from its form, and changes its permissions', function (): void {
    $page = signInToAccess(AccessWorld::ADMIN_EMAIL);
    $page->click('nav a:has-text("'.PanelPage::text('panel.nav.roles').'")');

    $page->assertPathIs('/cms/access/roles');
    assertAccessPage($page, rolesTexts('panel.roles.create'));
    $page->assertSee(AccessWorld::ADMIN_ROLE)
        ->assertSee(AccessWorld::EDITOR)
        ->assertSee(AccessWorld::PUBLISHER)
        ->assertSee(AccessWorld::ADMINS)
        ->assertSee('entry.publish');

    expect($page->script('document.querySelector(\'nav a[aria-current="page"]\')?.textContent'))->toBe(PanelPage::text('panel.nav.roles'));

    captureAccessScreenshot($page, 'access-roles', 1024, 720);
    captureAccessScreenshot($page, 'access-roles-mobile', 390, 760);

    // The form opens with the keyboard, and Enter in its handle submits it.
    $page->keys('button:has-text("'.PanelPage::text('panel.roles.create').'")', 'Enter');
    $page->assertVisible('[role="dialog"]');
    assertAccessPage($page, ['panel.roles.create_title', 'panel.roles.create_description', 'panel.roles.handle', 'panel.roles.ceiling', 'panel.roles.permissions', 'panel.roles.submit_create']);
    $page->type('[role="dialog"] input[name="handle"]', 'reviewers');
    $page->keys('[role="dialog"] input[name="handle"]', 'Enter');
    waitUntil($page, 'document.querySelector(\'[role="dialog"]\') === null');

    $page->assertSee('Saved')->assertSee('reviewers');
    assertAccessPage($page, rolesTexts('panel.roles.no_permissions'));

    // The permissions of the new role: the first option of the list, chosen with the keyboard.
    $page->click('button[aria-label="'.PanelPage::text('panel.roles.row_actions', ['handle' => 'reviewers']).'"]');
    $page->click('[role="menuitem"]:has-text("'.PanelPage::text('panel.roles.edit_permissions').'")');
    $page->assertVisible('[role="dialog"]');
    PanelPage::assertPage($page, ['panel.roles.unchanged', 'panel.roles.submit_permissions']);

    expect($page->script('document.querySelector(\'[role="dialog"] button[type="submit"]\')?.disabled'))->toBeTrue();

    $page->keys('[role="dialog"] button.cms-select__button', 'Enter');
    $page->assertVisible('[role="listbox"]');
    $page->keys('[role="listbox"] [role="option"]:first-child', 'Space');
    $page->keys('[role="listbox"] [role="option"]:first-child', 'Escape');
    $page->assertMissing('[role="listbox"]');

    expect($page->script('document.querySelector(\'[role="dialog"] button[type="submit"]\')?.disabled'))->toBeFalse();

    $page->click('[role="dialog"] button[type="submit"]');
    waitUntil($page, 'document.querySelector(\'[role="dialog"]\') === null');

    $page->assertSee('Saved')->assertSee('actor.list');
    assertAccessPage($page, rolesTexts());
});

it('gives a member of staff without the permissions neither page in the navigation, and says why at the address', function (): void {
    $page = signInToAccess(AccessWorld::OLE_EMAIL);

    PanelPage::assertPage($page, ['panel.home.body', 'panel.nav.account_me']);
    $page->assertDontSee(PanelPage::text('panel.nav.roles'))
        ->assertDontSee(PanelPage::text('panel.nav.grants'));

    $page->navigate('/cms/access/roles');

    $page->assertPathIs('/cms/access/roles');
    assertAccessPage($page, ['panel.roles.title', 'panel.problem.unauthorized']);
    $page->assertSee('unauthorized')
        ->assertDontSee(PanelPage::text('panel.roles.create'));
});
