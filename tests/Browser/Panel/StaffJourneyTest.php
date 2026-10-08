<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Identity\BreachedPasswords;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Maintenance\Actions\BootstrapAccess;
use Cbox\Cms\Core\Maintenance\Domain\Dto\BootstrapRequest;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Registry\Domain\RegistryCache;
use Cbox\Cms\Core\Tests\Registry\Fakes\FakeRegistryCache;
use Cbox\Cms\Identity\Staff\Actions\RegisterLocalStaff;
use Cbox\Cms\Identity\Staff\Domain\Dto\StaffRegistration;
use Cbox\Cms\Testkit\Identity\FakeBreachedPasswords;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use Cbox\Cms\Tests\Support\Registry\InstallationRegistry;
use DateInterval;
use Illuminate\Contracts\Console\Kernel;
use Pest\Browser\Api\PendingAwaitablePage;
use PHPUnit\Framework\AssertionFailedError;
use Workbench\App\Providers\WorkbenchServiceProvider;

/*
 * The exit criterion of B1 part 1 in Chromium (PRD 5.16, 5.10, 13.4, GUARDRAILS 8 and 9): the path
 * of docs/getting-started/first-login.md, against the build `composer panel:build` writes, this
 * checkout's test database and Valkey. The installation is set up as composer dev:prepare and the
 * page's commands set it up: cms:install, cms:sites:sync for the workbench's site, two members of
 * staff registered through cms:staff:create's action, RegisterLocalStaff, and the first of them
 * given the bootstrap role on the site's root node through cms:access:bootstrap's action,
 * BootstrapAccess. That first member of staff signs in, opens the command palette with the
 * keyboard and goes to the roles page with it, creates a role that is not administrative, goes to
 * the grants page with the palette, assigns the role to the second member of staff on the site's
 * root with the keyboard and the pickers, sees the grant in the list, and signs out. Every page and
 * state makes the shared assertions at the three widths of a phone, a tablet and a desktop: the
 * translated texts, an empty console, no script error, no axe finding at any impact, every WCAG
 * 2.2 AA rule, and no policy violation.
 *
 * With CMS_DOCS_SCREENSHOTS=1 the login page and the start page are also captured into
 * docs/screenshots/login.png, login-mobile.png and home.png, the images
 * docs/getting-started/first-login.md and docs/users/signing-in.md show.
 */

const JOURNEY_ADMIN_EMAIL = 'ada.lovelace@example.com';

const JOURNEY_ADMIN_NAME = 'Ada Lovelace';

const JOURNEY_STAFF_EMAIL = 'grace.hopper@example.com';

const JOURNEY_STAFF_NAME = 'Grace Hopper';

const JOURNEY_PASSWORD = 'a long and quite unusual sentence';

/** The role the journey creates and assigns: it holds no permission, so it is not administrative. */
const JOURNEY_ROLE = 'reviewers';

/** The widths every page is checked at: a phone, a tablet and a desktop. */
const JOURNEY_WIDTHS = [390, 820, 1440];

/**
 * Runs an Artisan command of the path in-process and gives its output; fails unless it exits 0.
 *
 * @param  array<string, string>  $arguments
 */
function journeyArtisan(string $command, array $arguments = []): string
{
    $artisan = app(Kernel::class);
    $status = $artisan->call($command, $arguments);
    $output = $artisan->output();

    expect($status)->toBe(0, $output);

    return $output;
}

/**
 * Registers a member of staff through cms:staff:create's action.
 */
function journeyStaff(string $email, string $name): ActorId
{
    return app(RegisterLocalStaff::class)->register(new StaffRegistration(new EmailAddress($email), new DisplayName($name), new Password(JOURNEY_PASSWORD)))->actor;
}

/**
 * Makes the shared assertions at the three widths, and leaves the page at a desktop width.
 *
 * @param  list<string>  $texts
 */
function assertJourneyPage(PendingAwaitablePage $page, array $texts): void
{
    foreach (JOURNEY_WIDTHS as $width) {
        $page->resize($width, 900);
        PanelPage::assertPage($page, $texts);
    }

    $page->resize(1440, 900);
}

/**
 * Saves the page as docs/screenshots/<key>.png when CMS_DOCS_SCREENSHOTS is set.
 */
function captureJourneyScreenshot(PendingAwaitablePage $page, string $key, int $width, int $height): void
{
    if (getenv('CMS_DOCS_SCREENSHOTS') !== '1') {
        return;
    }

    $page->resize($width, $height)->screenshot(false, 'docs-'.$key);
    copy(Codebase::root().'/tests/Browser/Screenshots/docs-'.$key.'.png', Codebase::root().'/docs/screenshots/'.$key.'.png');
    $page->resize(1440, 900);
}

/**
 * Waits until the expression is true on the page.
 */
function journeyWaitUntil(PendingAwaitablePage $page, string $expression): void
{
    expect(PanelProbe::eventually($page, $expression))->toBeTrue($expression);
}

/**
 * Opens the command palette with Ctrl+K, finds the page by its name in the navigation and opens it
 * with Enter.
 */
function openWithPalette(PendingAwaitablePage $page, string $nav, string $path): void
{
    $page->keys('body:first-of-type', 'Control+k');
    $page->assertVisible('[role="dialog"]');
    journeyWaitUntil($page, 'document.activeElement?.matches(\'[role="dialog"] input[type="search"]\') === true');

    $label = PanelPage::text($nav);
    $page->type('input[type="search"]', $label);
    journeyWaitUntil($page, 'document.querySelector(\'[role="dialog"] [role="option"] .cms-palette__label\')?.textContent === '.json_encode($label, JSON_THROW_ON_ERROR));
    $page->keys('input[type="search"]', 'Enter');

    $page->assertPathIs($path)
        ->assertMissing('[role="dialog"]');
}

/**
 * Chooses the first option of the grant form's combobox at the position by typing the text and
 * waiting until the list is filtered to it.
 */
function chooseJourneyOption(PendingAwaitablePage $page, int $position, string $text): void
{
    $input = '[role="dialog"] form > :nth-child('.$position.') input[role="combobox"]';

    $page->type($input, $text);
    journeyWaitUntil($page, 'document.querySelector(\'[role="listbox"] [role="option"]\')?.textContent?.toLowerCase().includes('.json_encode(strtolower($text), JSON_THROW_ON_ERROR).') === true');
    $page->keys($input, 'ArrowDown');
    $page->keys($input, 'Enter');
}

/**
 * The texts of the login page, with the message of the reason it was sent there.
 *
 * @return list<string>
 */
function journeyLoginTexts(string $reason): array
{
    return ['panel.login.title', 'panel.login.description', 'panel.login.email', 'panel.login.password', 'panel.login.forgot', $reason];
}

beforeEach(function (): void {
    app()->instance(BreachedPasswords::class, new FakeBreachedPasswords);

    $registry = InstallationRegistry::compile(app());
    $cache = new FakeRegistryCache;
    $cache->write($registry);
    app()->instance(RegistryCache::class, $cache);
    app()->instance(CompiledRegistry::class, $registry);

    app(PartitionFixtures::class)->coverClock(app(Clock::class), new DateInterval('P2D'));

    // composer dev:prepare's installation and sites, then the page's two commands, by their actions.
    journeyArtisan('cms:install');
    preg_match('/^registered '.WorkbenchServiceProvider::SITE.': site [0-9a-f-]{36}, root node ([0-9a-f-]{36}), /m', journeyArtisan('cms:sites:sync'), $synced);
    $root = NodeId::fromString($synced[1] ?? throw new AssertionFailedError('cms:sites:sync registered no workbench site.'));
    $admin = journeyStaff(JOURNEY_ADMIN_EMAIL, JOURNEY_ADMIN_NAME);
    journeyStaff(JOURNEY_STAFF_EMAIL, JOURNEY_STAFF_NAME);
    $bootstrap = app(BootstrapAccess::class)->run(new BootstrapRequest($admin, $root));

    expect($bootstrap->refusal)->toBeNull()
        ->and($bootstrap->result?->outcome())->toBe(Outcome::Committed);
});

it('signs the first member of staff in, opens the palette with the keyboard, assigns a role that is not administrative to another member of staff, sees the grant, and signs out', function (): void {
    $page = visit('/cms');

    // The login page.
    $page->assertPathIs('/cms/login');
    assertJourneyPage($page, journeyLoginTexts('panel.login.reason.required'));
    captureJourneyScreenshot($page, 'login', 1024, 720);
    captureJourneyScreenshot($page, 'login-mobile', 390, 760);

    $page->type('email', JOURNEY_ADMIN_EMAIL)
        ->type('password', JOURNEY_PASSWORD)
        ->click('button[type="submit"]');

    // The start page, with the navigation the bootstrap role gives, which a phone folds away.
    $page->assertPathIs('/cms');
    assertJourneyPage($page, ['panel.home.body', 'panel.palette.open', 'panel.home.sign_out']);
    PanelPage::assertPage($page, ['panel.nav.account_me', 'panel.nav.roles', 'panel.nav.grants']);
    captureJourneyScreenshot($page, 'home', 1024, 720);

    // The palette, open over the start page.
    $page->keys('body:first-of-type', 'Control+k');
    $page->assertVisible('[role="dialog"]');
    assertJourneyPage($page, ['panel.palette.pages', 'panel.palette.commands', 'panel.nav.roles', 'panel.nav.grants']);
    $page->keys('input[type="search"]', 'Escape');
    $page->assertMissing('[role="dialog"]');

    // A role that is not administrative: it holds no permission.
    openWithPalette($page, 'panel.nav.roles', '/cms/access/roles');
    assertJourneyPage($page, ['panel.roles.title', 'panel.roles.create']);
    $page->assertSee(config()->string('cbox-cms.access.bootstrap_role'));

    $page->keys('button:has-text("'.PanelPage::text('panel.roles.create').'")', 'Enter');
    $page->assertVisible('[role="dialog"]');
    assertJourneyPage($page, ['panel.roles.create_title', 'panel.roles.handle', 'panel.roles.submit_create']);
    $page->type('[role="dialog"] input[name="handle"]', JOURNEY_ROLE);
    $page->keys('[role="dialog"] input[name="handle"]', 'Enter');
    journeyWaitUntil($page, 'document.querySelector(\'[role="dialog"]\') === null');

    $page->assertSee('Saved')->assertSee(JOURNEY_ROLE);
    assertJourneyPage($page, ['panel.roles.title', 'panel.roles.no_permissions']);

    // The grant, on the site's root node.
    openWithPalette($page, 'panel.nav.grants', '/cms/access/grants');
    assertJourneyPage($page, ['panel.grants.title', 'panel.grants.assign']);
    $page->assertSee(JOURNEY_ADMIN_NAME)->assertDontSee(JOURNEY_STAFF_NAME);

    $page->keys('button:has-text("'.PanelPage::text('panel.grants.assign').'")', 'Enter');
    $page->assertVisible('[role="dialog"]');
    journeyWaitUntil($page, 'document.body.textContent?.includes('.json_encode(PanelPage::text('panel.grants.pickers_loading'), JSON_THROW_ON_ERROR).') === false');
    assertJourneyPage($page, ['panel.grants.assign_title', 'panel.grants.actor', 'panel.grants.role', 'panel.grants.node', 'panel.grants.submit_assign']);

    chooseJourneyOption($page, 1, 'Grace');
    chooseJourneyOption($page, 2, JOURNEY_ROLE);
    $page->keys('[role="dialog"] form > :nth-child(3) button', 'Enter');
    $page->assertVisible('[role="treegrid"]');
    $page->keys('[role="treegrid"] [role="row"]:has-text("'.WorkbenchServiceProvider::SITE.'")', 'Space');
    $page->click('[role="dialog"]:has([role="treegrid"]) button:has-text("Choose")');
    $page->assertMissing('[role="treegrid"]');
    $page->keys('[role="dialog"] form button[type="submit"]', 'Enter');
    journeyWaitUntil($page, 'document.querySelector(\'[role="dialog"]\') === null');

    // The grant in the list.
    $page->assertSee('Saved')
        ->assertSee(JOURNEY_STAFF_NAME)
        ->assertSee(JOURNEY_STAFF_EMAIL)
        ->assertSee(JOURNEY_ROLE);
    assertJourneyPage($page, ['panel.grants.title', 'panel.grants.assigned', 'panel.access.effect.allow']);

    // Signing out lands on the login page.
    $page->click('main form button[type="submit"]:has-text("'.PanelPage::text('panel.home.sign_out').'")');
    $page->assertPathIs('/cms/login');
    assertJourneyPage($page, journeyLoginTexts('panel.login.reason.signed_out'));

    $page->navigate('/cms');
    $page->assertPathIs('/cms/login');
});
