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
use Cbox\Cms\Tests\Support\Browser\ShellFixture;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Pest\Browser\Api\PendingAwaitablePage;

/*
 * The panel's shell and the who-am-I page in Chromium (PRD 5.16, 13.4, GUARDRAILS 8 and 9), against
 * the build `composer panel:build` writes, this checkout's test database and Valkey: a member of
 * staff with a profile and one grant, written by the testkit's fixture writers, signs in, finds
 * the core's entry "Who am I" in the shell's navigation, opens the page, and sees their name,
 * email, actor id, kind of account, state and the grant with its role, node, effect and language,
 * as actor.me read them through the query pipeline as the person; the entry is marked as the
 * current page there. A member of staff who holds no grant at all still reaches the page, because
 * actor.me needs no permission, and sees that they hold no access. Every page makes the shared
 * assertions at the three widths of a phone, a tablet and a desktop: its translated text, an
 * empty console, no script error, no axe finding at any impact, every WCAG 2.2 AA rule, no
 * policy violation, and nothing that scrolls the page sideways.
 *
 * With CMS_DOCS_SCREENSHOTS=1 the page is also captured into docs/screenshots/account-me.png, the
 * image docs/addons/panel/pages.md shows.
 */

const SHELL_EMAIL = 'mette.holm@example.com';

const SHELL_NAME = 'Mette Holm';

const SHELL_PASSWORD = 'correct horse battery staple';

const SHELL_ROLE = 'shelleditor';

/** The widths every page is checked at: a phone, a tablet and a desktop. */
const SHELL_WIDTHS = [390, 820, 1440];

/**
 * The world of the test, which beforeEach writes for each test.
 */
function shellWorld(?ShellFixture $world = null): ShellFixture
{
    /** @var ShellFixture|null $current */
    static $current = null;

    if ($world instanceof ShellFixture) {
        $current = $world;
    }

    return $current ?? throw new LogicException('No shell world.');
}

/**
 * Signs the member of staff in and lands on the start page.
 */
function signInToPanel(string $email): PendingAwaitablePage
{
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    $page->type('email', $email)
        ->type('password', SHELL_PASSWORD)
        ->click('button[type="submit"]');
    $page->assertPathIs('/cms');

    return $page;
}

/**
 * Makes the shared assertions at the three widths, and leaves the page at a desktop width.
 *
 * @param  list<string>  $texts
 */
function assertShellPage(PendingAwaitablePage $page, array $texts): void
{
    foreach (SHELL_WIDTHS as $width) {
        $page->resize($width, 900);
        PanelPage::assertPage($page, $texts);
    }

    $page->resize(1440, 900);
}

/**
 * Saves the page as docs/screenshots/<key>.png when CMS_DOCS_SCREENSHOTS is set.
 */
function captureShellScreenshot(PendingAwaitablePage $page, string $key): void
{
    if (getenv('CMS_DOCS_SCREENSHOTS') !== '1') {
        return;
    }

    $page->resize(1024, 720)->screenshot(false, 'docs-'.$key);
    copy(Codebase::root().'/tests/Browser/Screenshots/docs-'.$key.'.png', Codebase::root().'/docs/screenshots/'.$key.'.png');
}

beforeEach(function (): void {
    $clock = new FakeClock;
    $ids = new FakeIdGenerator(seed: 13, clock: $clock);
    $connections = app(ConnectionResolverInterface::class);
    $identity = new PostgresIdentitySeeder($connections, $clock, $ids);
    $access = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, $ids);
    $root = new PostgresStructureFixtures($connections, $clock, $ids)->site('shell', [new Locale('da')])->root->id;
    $actor = $identity->addActor(ActorClass::Staff, ActorState::Active)->id;

    // The grant commands come in later tasks, so the profile and the grant are written by the
    // testkit's fixture writers as the owner role.
    $identity->addProfile($actor, new ActorProfile(new DisplayName(SHELL_NAME), new EmailAddress(SHELL_EMAIL)));
    $access->grant($actor, $access->role(SHELL_ROLE, ClassificationAccess::Internal, [new CommandName('entry.revise')]), $root, locales: [new Locale('da')]);
    app(LocalCredentialStore::class)->bind($actor, new LoginIdentifier(SHELL_EMAIL), app(PasswordHasher::class)->hash(new Password(SHELL_PASSWORD)));

    shellWorld(new ShellFixture($actor, $root));
});

/**
 * The texts of the who-am-I page.
 *
 * @return list<string>
 */
function accountMeTexts(string ...$more): array
{
    return array_values(['panel.account_me.title', 'panel.account_me.description', 'panel.account_me.profile', 'panel.account_me.name', 'panel.account_me.email', 'panel.account_me.actor', 'panel.account_me.class', 'panel.account_me.state', 'panel.account_me.grants', 'panel.home.sign_out', ...$more]);
}

it('opens the who-am-I page from the navigation and shows the person their profile, actor and grants', function (): void {
    $world = shellWorld();
    $page = signInToPanel(SHELL_EMAIL);

    // The start page at every width; the navigation itself is behind the bar's button on a phone,
    // so its entry is read where the page is wide enough to show it, as the click below needs.
    assertShellPage($page, ['panel.home.body']);
    PanelPage::assertPage($page, ['panel.home.body', 'panel.nav.account_me']);

    $page->click('nav a:has-text("'.PanelPage::text('panel.nav.account_me').'")');

    $page->assertPathIs('/cms/account/me');
    assertShellPage($page, accountMeTexts('panel.account_me.class.staff', 'panel.account_me.state.active', 'panel.account_me.effect.allow', 'panel.account_me.grant.role', 'panel.account_me.grant.node', 'panel.account_me.grant.effect', 'panel.account_me.grant.locales'));
    $page->assertSee(SHELL_NAME)
        ->assertSee(SHELL_EMAIL)
        ->assertSee($world->actor->toString())
        ->assertSee(SHELL_ROLE)
        ->assertSee($world->root->toString())
        ->assertSee('da');

    expect($page->script('document.querySelector(\'nav a[aria-current="page"]\')?.textContent'))->toBe(PanelPage::text('panel.nav.account_me'))
        ->and($page->script('document.title'))->toContain(PanelPage::text('panel.account_me.title'));

    captureShellScreenshot($page, 'account-me');

    $page->click('button[type="submit"]');

    $page->assertPathIs('/cms/login');
});

it('shows a member of staff without any grant their account and that they hold no access', function (): void {
    $world = shellWorld();
    $clock = new FakeClock;
    $identity = new PostgresIdentitySeeder(app(ConnectionResolverInterface::class), $clock, new FakeIdGenerator(seed: 17, clock: $clock));
    $actor = $identity->addActor(ActorClass::Staff, ActorState::Active)->id;
    $email = 'ole.frost@example.com';

    $identity->addProfile($actor, new ActorProfile(new DisplayName('Ole Frost'), new EmailAddress($email)));
    app(LocalCredentialStore::class)->bind($actor, new LoginIdentifier($email), app(PasswordHasher::class)->hash(new Password(SHELL_PASSWORD)));

    $page = signInToPanel($email);
    $page->navigate('/cms/account/me');

    $page->assertPathIs('/cms/account/me');
    assertShellPage($page, accountMeTexts('panel.account_me.no_grants_title', 'panel.account_me.no_grants_body'));
    $page->assertSee($email)
        ->assertSee($actor->toString())
        ->assertDontSee($world->actor->toString())
        ->assertDontSee(SHELL_EMAIL);
});
