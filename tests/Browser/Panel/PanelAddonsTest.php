<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use ArrayObject;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\GrantId;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Panel\Boundary\AddonAssetResponse;
use Cbox\Cms\Panel\Domain\Dto\BundleDirectories;
use Cbox\Cms\Panel\Domain\Dto\ImportMap;
use Cbox\Cms\Panel\Domain\Dto\ServedBundles;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Panel\Boundary\FillOrderScript;
use Cbox\Cms\Tests\Support\Browser\FixtureAddonWorld;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use LogicException;
use Pest\Browser\Api\PendingAwaitablePage;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;
use Workbench\FixtureAddon\FixtureArticle;

/*
 * The panel's extension model in Chromium (PRD 13.4, section 7 of the panel extension
 * architecture), against the build `composer panel:build` writes, this checkout's test database
 * and Valkey, with the workbench's fixture addon contributing to every kind of contribution and
 * every point of block B1 from its signed bundle (FixtureAddonServiceProvider):
 *
 * - order: the who-am-I page renders the addon's sections in the order cms:panel:fills lists
 *   them, priority with the lowest first, the one the kill switch disables left out;
 * - isolation: a contribution that throws is replaced by a notice that names the addon, with the
 *   detail for a viewer with internal access, and the rest of the page renders;
 * - conflicts and the experimental opt-in: cms:build refuses a manifest with two contributions of
 *   one id, and one that contributes to an experimental point it does not accept;
 * - permission filtering: a viewer without the addon's permission is never sent its page, its nav
 *   entry, its action or its section that reads data, the page answers 404, and the modules of
 *   those contributions are never loaded;
 * - the reads cap: the addon reads public fields, so its page and section get the articles
 *   without their titles, classified internal, whatever the viewer may read;
 * - the policy: every page makes the shared assertions, no violation reported;
 * - credential routes: the login page shows the addon's notice as data and names no addon in its
 *   import map;
 * - hash refusal: a file of the bundle whose bytes changed after cms:build is refused by the
 *   server, past the browser's cache;
 * - the kill switch: a whole addon disabled at run time is gone at the next request;
 * - the form of grant.assign: the addon's blocking check mirrors its hook and holds a grant to
 *   the viewer themselves, and its four-eyes step runs before the submit of a grant to a
 *   colleague, which the kernel then commits;
 * - and the form of the addon's own command fixtureaddon.slug.set: the input of the member bound
 *   to the addon's own value class is the addon's replacement, which shapes what is typed into a
 *   slug, and the run writes the slug in a new revision, which the addon's sections and page then
 *   show.
 *
 * The addon's texts show as their keys: an addon's catalogue is not loaded by the panel yet.
 */

/** The title of the article the editor writes, which the fixture addon's reads cap keeps from it. */
const ADDONS_TITLE = 'A quiet week';

const ADDONS_SLUG = 'a-quiet-week';

/** The slug the editor sets afterwards through the addon's own command, typed with capitals and punctuation. */
const ADDONS_TYPED_SLUG = 'Quiet Week, Revisited!';

const ADDONS_NEW_SLUG = 'quiet-week-revisited';

/**
 * The world of the test, which beforeEach seeds for each test.
 */
function addonsWorld(?FixtureAddonWorld $world = null): FixtureAddonWorld
{
    /** @var FixtureAddonWorld|null $current */
    static $current = null;

    if ($world instanceof FixtureAddonWorld) {
        $current = $world;
    }

    return $current ?? throw new LogicException('No world.');
}

/**
 * The contributions a point renders, in document order.
 *
 * @return list<string>
 */
function addonsFillOrder(PendingAwaitablePage $page, string $point): array
{
    $rendered = $page->script(FillOrderScript::for($point));

    return is_array($rendered) ? array_values(array_filter($rendered, is_string(...))) : [];
}

/**
 * The imports of the page's import map, by specifier.
 *
 * @return array<string, string>
 */
function addonsImports(PendingAwaitablePage $page): array
{
    $map = $page->script('JSON.parse(document.querySelector(\'script[type="importmap"]\').textContent)');
    $imports = is_array($map) && is_array($map['imports'] ?? null) ? $map['imports'] : [];
    $listed = [];

    foreach ($imports as $specifier => $url) {
        if (is_string($url)) {
            $listed[(string) $specifier] = $url;
        }
    }

    return $listed;
}

/**
 * Records, per path, the ids of the fills every page the kernel answers sends in cms.contributions,
 * by point: the browser plugin serves the application in this process, so the test hears each
 * answer, a full page load with its root view and an Inertia visit with its JSON alike.
 *
 * @return ArrayObject<string, array<string, list<string>>>
 */
function addonsRecordSentFills(): ArrayObject
{
    /** @var ArrayObject<string, array<string, list<string>>> $sent */
    $sent = new ArrayObject;

    Event::listen(RequestHandled::class, static function (RequestHandled $handled) use ($sent): void {
        // An Inertia visit answers the page as JSON; a full page load renders the root view with
        // the page among its data, held as objects, so it is read back through JSON too.
        $original = $handled->response->getOriginalContent();
        $page = $original instanceof View ? json_decode((string) json_encode($original->getData()['page'] ?? null), true) : $original;

        $props = is_array($page) && is_array($page['props'] ?? null) ? $page['props'] : null;
        $cms = is_array($props) && is_array($props['cms'] ?? null) ? $props['cms'] : null;
        $contributions = is_array($cms) && is_array($cms['contributions'] ?? null) ? $cms['contributions'] : null;

        if (! is_array($contributions) || ! is_array($contributions['points'] ?? null)) {
            return;
        }

        $points = [];

        foreach ($contributions['points'] as $point) {
            if (! is_array($point) || ! is_string($point['point'] ?? null) || ! is_array($point['fills'] ?? null)) {
                continue;
            }

            $points[$point['point']] = array_values(array_filter(array_map(static fn (mixed $fill): ?string => is_array($fill) && is_string($fill['id'] ?? null) ? $fill['id'] : null, $point['fills'])));
        }

        $sent['/'.ltrim($handled->request->path(), '/')] = $points;
    });

    return $sent;
}

/**
 * The paths of the addon's files the document loaded so far.
 *
 * @return list<string>
 */
function addonsLoadedFiles(PendingAwaitablePage $page): array
{
    $names = $page->script('performance.getEntriesByType("resource").map((entry) => new URL(entry.name).pathname).filter((path) => path.startsWith("/cms/addons/"))');

    return is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
}

/**
 * Waits up to 12 seconds for the expression to be truthy, polling in rounds of 4 seconds, each
 * well within the browser plugin's script timeout; the deferred data of a page's pickers may take
 * a few seconds on a loaded server.
 */
function addonsEventually(PendingAwaitablePage $page, string $expression): bool
{
    for ($round = 0; $round < 3; $round++) {
        $value = $page->script(<<<JS
            () => new Promise((resolve) => {
                const started = Date.now();
                const poll = () => {
                    const value = ({$expression});
                    if (value || Date.now() - started > 4000) {
                        resolve(Boolean(value));
                    } else {
                        setTimeout(poll, 50);
                    }
                };
                poll();
            })
            JS);

        if ($value === true) {
            return true;
        }
    }

    return false;
}

/**
 * Chooses the option of the core's picker of the member by typing the text and the arrow keys.
 */
function addonsChoose(PendingAwaitablePage $page, string $picker, string $text): void
{
    $input = '[data-cms-contribution="'.$picker.'"] input[role="combobox"]';

    // The picker shows its combobox while its list loads and once it arrived; typing opens the
    // list and filters it, so the test waits for the list.
    $page->assertVisible($input);
    expect(addonsEventually($page, 'document.body.textContent?.includes('.json_encode(PanelPage::text('panel.pickers.roles_loading'), JSON_THROW_ON_ERROR).') === false && document.body.textContent?.includes('.json_encode(PanelPage::text('panel.pickers.actors_loading'), JSON_THROW_ON_ERROR).') === false'))->toBeTrue('the pickers loaded');
    $page->fill($input, '');
    $page->type($input, $text);
    expect(addonsEventually($page, 'document.querySelector(\'[role="listbox"] [role="option"]\')?.textContent?.toLowerCase().includes('.json_encode(strtolower($text), JSON_THROW_ON_ERROR).') === true'))->toBeTrue($picker.' offers '.$text);
    $page->keys($input, 'ArrowDown');
    $page->keys($input, 'Enter');
}

/**
 * Chooses the site's root in the core's node picker.
 */
function addonsChooseRoot(PendingAwaitablePage $page): void
{
    $page->click('[data-cms-contribution="cms.node-picker"] .cms-node-picker button');
    $page->assertVisible('[role="dialog"]');
    $page->click('[role="dialog"] .cms-tree__item:has-text("'.FixtureAddonWorld::SITE.'")');
    $page->click('[role="dialog"] button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->assertMissing('[role="dialog"]');
}

beforeEach(function (): void {
    addonsWorld(FixtureAddonWorld::seed(app()));
});

it('shows the addon\'s notice on the login page as data alone, and every contribution of the addon to the editor: the action opening the form, the aside, the decorators, the dry run section, the step, the input of its own value class on its own command, the observer, the sections in order with the reads cap, and the page', function (): void {
    $world = addonsWorld();
    $entry = new EntryId(new FakeIdGenerator(seed: 97)->next());

    // The login page: the notice as data, and no addon in the import map or the document.
    $login = visit('/cms/login');

    PanelPage::assertPage($login, ['panel.login.title']);
    $login->assertSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::LOGIN_NOTICE.'"]', 'fixtureaddon.login_notice.message');

    expect(addonsFillOrder($login, 'login.notice@1'))->toBe([FixtureAddonServiceProvider::LOGIN_NOTICE])
        ->and(array_keys(addonsImports($login)))->not->toContain(ImportMap::ADDON_SPECIFIER.FixtureAddonServiceProvider::NAMESPACE)
        ->and($login->script('document.documentElement.outerHTML.includes("/cms/addons/")'))->toBeFalse();

    // The start page: the nav entry of the addon's page and the action of the viewer's menu.
    $page = FixtureAddonWorld::signIn(FixtureAddonWorld::EDITOR_EMAIL);

    PanelPage::assertPage($page, ['panel.home.body', 'panel.nav.account_me']);
    $page->assertSee('fixtureaddon.nav.articles');

    expect(addonsFillOrder($page, 'shell.user-menu@1'))->toBe([FixtureAddonServiceProvider::NEW_ARTICLE])
        ->and(addonsImports($page))->toHaveKey(ImportMap::ADDON_SPECIFIER.FixtureAddonServiceProvider::NAMESPACE);

    // The action opens the form of entry.create, where the aside and the submit decorator show.
    $page->click('button:has-text("fixtureaddon.new_article.label")');
    $page->assertPathIs('/cms/commands/entry.create/v1');
    PanelPage::assertPage($page, ['panel.action.entry.create.title', 'panel.command_form.run', 'panel.command_form.try']);
    $page->assertSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::SLUG_HELP.'"]', 'fixtureaddon.slug_help.title')
        ->assertSee('fixtureaddon.submit_note.description');

    addonsChooseRoot($page);
    $page->type('entry', $entry->toString())
        ->type('type', FixtureArticle::TYPE_ID)
        ->fill('fields', json_encode(['fixture_title' => ADDONS_TITLE, 'fixture_featured' => true, 'ext' => ['fixtureaddon' => ['fixture_slug' => ADDONS_SLUG]]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

    // A dry run: the addon's section below what would change.
    $page->click(PanelPage::text('panel.command_form.try'));
    $page->assertSee(PanelPage::text('panel.command_form.dry_run_report'))
        ->assertSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::DRY_RUN_NOTE.'"]', 'fixtureaddon.dry_run_note.title');
    PanelPage::assertPage($page, ['panel.command_form.dry_run_report']);

    // The run: the addon's step, the core's confirmation, the commit, and the receipt decorator.
    $page->click(PanelPage::text('panel.command_form.run'));
    $page->assertSee('fixtureaddon.slug_review.slug');
    $page->click('button:has-text("fixtureaddon.slug_review.use")');
    $page->click('button:text-is("'.PanelPage::text('panel.host.confirm').'")');
    $page->assertSee(PanelPage::text('panel.command_form.committed_title'))
        ->assertSee('fixtureaddon.receipt_note.saved');
    PanelPage::assertPage($page, ['panel.command_form.committed_title']);

    // The addon's own command, from the palette: the input of the member bound to its value class
    // is the addon's replacement, the only one rendered on this form, which shapes what is typed
    // into a slug; the run, with no step on this form, commits a new revision with the slug.
    $version = StorageTables::superuser()->table('variant_heads')->where('entry_id', $entry->toString())->where('variant', 'shared')->value('version');

    $page->keys('body:first-of-type', 'Control+k');
    $page->assertVisible('[role="dialog"]');
    $page->type('input[type="search"]', 'slug.set');
    expect(PanelProbe::eventually($page, 'document.querySelectorAll(\'[role="dialog"] [role="option"]\').length === 1'))->toBeTrue('the palette offers the addon\'s command');
    $page->keys('input[type="search"]', 'Enter');
    $page->assertPathIs('/cms/commands/fixtureaddon.slug.set/v1');
    PanelPage::assertPage($page, ['panel.command_form.run']);
    $page->assertVisible('[data-cms-contribution="'.FixtureAddonServiceProvider::SLUG_INPUT.'"] input[name="slug"]')
        ->assertSee('fixtureaddon.slug_input.description')
        ->assertDontSee('fixtureaddon.slug_help.title');

    expect(addonsFillOrder($page, 'command.form.field@1'))->toBe([FixtureAddonServiceProvider::SLUG_INPUT]);

    // A number field keeps its name on a hidden input; the visible input is typed into.
    $page->type('entry', $entry->toString())
        ->type('.cms-field:has(input[name="version"]) input.cms-input', (string) (is_numeric($version) ? (int) $version : 0))
        ->type('slug', ADDONS_TYPED_SLUG);

    expect($page->script('document.querySelector("[data-fixtureaddon-slug-preview]")?.dataset.fixtureaddonSlugPreview'))->toBe(ADDONS_NEW_SLUG)
        ->and($page->script('document.querySelector(\'input[name="slug"]\')?.value'))->toBe(ADDONS_NEW_SLUG.'-');

    $page->click(PanelPage::text('panel.command_form.run'));
    $page->assertSee(PanelPage::text('panel.command_form.committed_title'));
    PanelPage::assertPage($page, ['panel.command_form.committed_title']);

    expect(StorageTables::superuser()->table('app__fixture_article')->where('cms_entry_id', $entry->toString())->where('cms_stage', 'draft')->value('ext__fixtureaddon__fixture_slug'))->toBe(ADDONS_NEW_SLUG);

    // The who-am-I page: the sections in render order, the faulty one disabled by the workbench's
    // kill switch; the observer recorded the last commit; the reads cap keeps the title from the
    // addon; the slug is the one the addon's command set.
    $page->click('nav a:has-text("'.PanelPage::text('panel.nav.account_me').'")');
    $page->assertPathIs('/cms/account/me');
    $page->assertSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::RECENT_ACTIVITY.'"]', 'fixtureaddon.slug.set@1')
        ->assertSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::RECENT_ACTIVITY.'"]', 'fixtureaddon.recent_activity.outcome.committed')
        ->assertSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::MY_ARTICLES.'"]', ADDONS_NEW_SLUG)
        ->assertDontSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::MY_ARTICLES.'"]', ADDONS_SLUG)
        ->assertDontSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::MY_ARTICLES.'"]', ADDONS_TITLE)
        ->assertDontSee('fixtureaddon.articles.title');
    $page->assertSee('fixtureaddon.recent_activity.title')
        ->assertSee('fixtureaddon.my_articles.title');
    PanelPage::assertPage($page, ['panel.account_me.title']);

    expect(addonsFillOrder($page, 'account.me.sections@1'))->toBe([FixtureAddonServiceProvider::RECENT_ACTIVITY, FixtureAddonServiceProvider::MY_ARTICLES]);

    // The addon's page, from its nav entry: the same data, without the title.
    $page->click('nav a:has-text("fixtureaddon.nav.articles")');
    $page->assertPathIs('/cms/x/fixtureaddon/articles');
    $page->assertSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::ARTICLES.'"]', ADDONS_NEW_SLUG)
        ->assertSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::ARTICLES.'"]', $entry->toString())
        ->assertDontSee(ADDONS_TITLE);
    $page->assertSee('fixtureaddon.articles.page_title');
    PanelPage::assertPage($page, ['panel.home.sign_out']);

    expect($page->script('document.querySelector(\'nav a[aria-current="page"]\')?.textContent'))->toBe('fixtureaddon.nav.articles')
        ->and(StorageTables::superuser()->table('app__fixture_article')->where('fixture_title', ADDONS_TITLE)->count())->toBeGreaterThanOrEqual(1);

    // The grants and roles pages: the addon's notes.
    $page->click('nav a:has-text("'.PanelPage::text('panel.nav.grants').'")');
    $page->assertPathIs('/cms/access/grants');
    $page->assertSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::FOUR_EYES_NOTE.'"]', 'fixtureaddon.four_eyes_note.title');
    PanelPage::assertPage($page, ['panel.grants.title']);

    $page->click('nav a:has-text("'.PanelPage::text('panel.nav.roles').'")');
    $page->assertPathIs('/cms/access/roles');
    $page->assertSeeIn('[data-cms-contribution="'.FixtureAddonServiceProvider::ARTICLES_PERMISSION_NOTE.'"]', 'fixtureaddon.articles_permission.title');
    PanelPage::assertPage($page, ['panel.roles.title']);

    expect($world->editor->toString())->not->toBe('');
});

it('never sends a viewer without the permission the addon\'s page, nav entry, action or data section, answers the page 404, and never loads their modules', function (): void {
    $sent = addonsRecordSentFills();
    $page = FixtureAddonWorld::signIn(FixtureAddonWorld::READER_EMAIL);

    PanelPage::assertPage($page, ['panel.home.body']);
    $page->assertDontSee('fixtureaddon.nav.articles')
        ->assertDontSee('fixtureaddon.new_article.label');

    $home = $sent['/cms'] ?? [];

    expect($home['shell.nav@1'] ?? [])->not->toContain(FixtureAddonServiceProvider::ARTICLES_LINK)
        ->and($home)->not->toHaveKey('shell.page@1')
        ->and($home)->not->toHaveKey('shell.user-menu@1')
        ->and($home['panel.observe.command@1'] ?? [])->toBe([FixtureAddonServiceProvider::ACTIVITY]);

    $page->navigate('/cms/x/fixtureaddon/articles');
    PanelPage::assertPage($page, ['panel.not_found.title']);

    $page->navigate('/cms/account/me');
    $page->assertSee('fixtureaddon.recent_activity.none_title');
    PanelPage::assertPage($page, ['panel.account_me.title']);

    expect(addonsFillOrder($page, 'account.me.sections@1'))->toBe([FixtureAddonServiceProvider::RECENT_ACTIVITY])
        ->and($sent['/cms/account/me']['account.me.sections@1'] ?? [])->toBe([FixtureAddonServiceProvider::RECENT_ACTIVITY]);

    $loaded = addonsLoadedFiles($page);

    expect($loaded)->not->toBe([])
        ->and(array_values(array_filter($loaded, static fn (string $path): bool => str_contains($path, '/Articles-') || str_contains($path, '/MyArticles-'))))->toBe([]);
});

it('isolates a contribution that throws in a notice that names the addon, keeps the rest of the page, and leaves a whole addon out once the kill switch disables it', function (): void {
    // The workbench disables the faulty section; this test turns the kill switch off.
    app(Repository::class)->set('cbox-cms.panel.disabled', []);

    $sent = addonsRecordSentFills();
    $page = FixtureAddonWorld::signIn(FixtureAddonWorld::EDITOR_EMAIL);
    $page->navigate('/cms/account/me');

    $page->assertSee(PanelPage::text('panel.host.failed_title', ['addon' => FixtureAddonServiceProvider::NAMESPACE]))
        ->assertSee('fixtureaddon.faulty throws on purpose')
        ->assertSee('fixtureaddon.recent_activity.title')
        ->assertSee('fixtureaddon.my_articles.title')
        ->assertSee(PanelPage::text('panel.account_me.profile'))
        ->assertSee(FixtureAddonWorld::EDITOR_EMAIL);

    // React reports the error the boundary caught on the console, so the console is not empty
    // here; the page still throws nothing, keeps its policy and passes axe.
    $page->assertNoJavaScriptErrors();
    PanelPage::assertWcag22AA($page);
    PanelPage::assertNoPolicyViolations($page);

    expect($sent['/cms/account/me']['account.me.sections@1'] ?? [])->toBe([FixtureAddonServiceProvider::RECENT_ACTIVITY, FixtureAddonServiceProvider::MY_ARTICLES, FixtureAddonServiceProvider::FAULTY])
        ->and(addonsFillOrder($page, 'account.me.sections@1'))->toBe([FixtureAddonServiceProvider::RECENT_ACTIVITY, FixtureAddonServiceProvider::MY_ARTICLES]);

    // The whole addon disabled at run time: gone at the next request, without a rebuild.
    app(Repository::class)->set('cbox-cms.panel.disabled', ['addons' => [FixtureAddonServiceProvider::NAMESPACE]]);
    $page->navigate('/cms/account/me');

    PanelPage::assertPage($page, ['panel.account_me.title']);
    $page->assertDontSee('fixtureaddon.nav.articles')
        ->assertDontSee('fixtureaddon.recent_activity.title');

    expect(addonsFillOrder($page, 'account.me.sections@1'))->toBe([])
        ->and(array_keys($sent['/cms/account/me'] ?? []))->not->toContain('account.me.sections@1', 'shell.page@1', 'shell.user-menu@1', 'panel.observe.command@1')
        // The import map still names the bundle, as cms:build compiled it; with no contribution
        // active, the host imports nothing of the addon.
        ->and(addonsLoadedFiles($page))->toBe([]);

    // One contribution disabled: the others stay.
    app(Repository::class)->set('cbox-cms.panel.disabled', ['contributions' => [FixtureAddonServiceProvider::MY_ARTICLES, FixtureAddonServiceProvider::FAULTY]]);
    $page->navigate('/cms/account/me');

    $page->assertSee('fixtureaddon.recent_activity.title');
    PanelPage::assertPage($page, ['panel.account_me.title']);

    expect(addonsFillOrder($page, 'account.me.sections@1'))->toBe([FixtureAddonServiceProvider::RECENT_ACTIVITY]);
});

it('holds a grant to the viewer themselves with the mirrored check, and runs the four-eyes step before a grant to a colleague, which the kernel commits', function (): void {
    $world = addonsWorld();
    $grant = new GrantId(new FakeIdGenerator(seed: 98)->next());
    $page = FixtureAddonWorld::signIn(FixtureAddonWorld::EDITOR_EMAIL);

    $page->navigate('/cms/commands/grant.assign/v1');
    PanelPage::assertPage($page, ['panel.action.grant.assign.title', 'panel.command_form.run']);
    $page->assertDontSee('fixtureaddon.slug_help.title');

    $page->type('grant', $grant->toString());
    $page->click(':nth-match(form button.cms-select__button, 1)');
    $page->click('[role="listbox"] [role="option"]:has-text("allow")');
    addonsChooseRoot($page);
    addonsChoose($page, 'cms.role-picker', FixtureAddonWorld::REPORTER_ROLE);

    // A grant to oneself: the addon's error at the grantee, mirrored by DenySelfGrant, holds the run.
    addonsChoose($page, 'cms.actor-picker', FixtureAddonWorld::EDITOR_NAME);
    $page->assertSee('fixtureaddon.self_grant.message');
    $page->click(PanelPage::text('panel.command_form.run'));
    $page->assertSee(PanelPage::text('panel.command_form.held_title'));
    PanelPage::assertPage($page, ['panel.command_form.held_title']);

    expect(StorageTables::superuser()->table('grants')->where('id', $grant->toString())->count())->toBe(0);

    // A grant to a colleague: the step asks for a second person's review; stopping cancels the
    // flow in the addon's name, and going on reaches the core's confirmation and the commit.
    addonsChoose($page, 'cms.actor-picker', FixtureAddonWorld::COLLEAGUE_NAME);
    $page->assertDontSee('fixtureaddon.self_grant.message');
    $page->click(PanelPage::text('panel.command_form.run'));
    $page->assertSee('fixtureaddon.four_eyes.title')
        ->assertSee(PanelPage::text('panel.command_form.step_of', ['step' => 1, 'addon' => FixtureAddonServiceProvider::NAMESPACE]));

    expect($page->script('document.querySelector("[data-fixtureaddon-grantee]")?.dataset.fixtureaddonGrantee'))->toBe($world->colleague->toString());

    $page->click('button:has-text("fixtureaddon.four_eyes.stop")');
    $page->assertSee(PanelPage::text('panel.host.step_cancelled', ['addon' => FixtureAddonServiceProvider::NAMESPACE]));

    expect(StorageTables::superuser()->table('grants')->where('id', $grant->toString())->count())->toBe(0);

    $page->click(PanelPage::text('panel.command_form.back'));
    $page->click(PanelPage::text('panel.command_form.run'));
    $page->click('button:has-text("fixtureaddon.four_eyes.reviewed")');
    $page->assertSee(PanelPage::text('panel.host.confirm_title'));
    $page->click('button:text-is("'.PanelPage::text('panel.host.confirm').'")');
    $page->assertSee(PanelPage::text('panel.command_form.committed_title'));
    PanelPage::assertPage($page, ['panel.command_form.committed_title']);

    $rows = StorageTables::superuser()->table('grants')->where('id', $grant->toString())->get(['actor_id', 'effect']);

    expect($rows->count())->toBe(1)
        ->and($rows->first()?->actor_id)->toBe($world->colleague->toString())
        ->and($rows->first()?->effect)->toBe('allow');
});

it('refuses a file of the bundle whose bytes changed after cms:build, on the server and past the browser\'s cache', function (): void {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().'/cms-fixture-addon-bundle-'.bin2hex(random_bytes(6));
    $files->copyDirectory(dirname(__DIR__, 3).'/workbench/addons/fixtureaddon/dist/panel', $directory);
    app()->instance(BundleDirectories::class, new BundleDirectories([FixtureAddonServiceProvider::NAMESPACE => $directory]));
    app()->forgetInstance(ServedBundles::class);

    try {
        $page = FixtureAddonWorld::signIn(FixtureAddonWorld::EDITOR_EMAIL);

        PanelPage::assertPage($page, ['panel.home.body']);

        $entry = addonsImports($page)[ImportMap::ADDON_SPECIFIER.FixtureAddonServiceProvider::NAMESPACE] ?? '';

        expect($entry)->toEndWith('/'.FixtureAddonWorld::BUNDLE_ENTRY);

        $served = $page->script('() => fetch("'.$entry.'", { cache: "no-store" }).then((response) => response.status)');

        expect($served)->toBe(200);

        $files->append($directory.'/'.FixtureAddonWorld::BUNDLE_ENTRY, "/* changed after cms:build */\n");

        $refused = $page->script('() => fetch("'.$entry.'", { cache: "no-store" }).then(async (response) => ({ status: response.status, body: await response.json() }))');
        $refused = is_array($refused) ? $refused : [];
        $body = is_array($refused['body'] ?? null) ? $refused['body'] : [];
        $failed = $page->script('() => import("'.$entry.'?after=change").then(() => "ran", (error) => "failed " + error.name)');

        expect($refused['status'] ?? null)->toBe(500)
            ->and($body['code'] ?? null)->toBe(AddonAssetResponse::CODE)
            ->and($failed)->toStartWith('failed');
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('is refused by cms:build with two contributions of one id, and with an experimental point the manifest does not accept', function (): void {
    $own = new FixtureAddonServiceProvider(app())->addonManifest();
    $contributions = FixtureAddonServiceProvider::contributions();

    expect(FixtureAddonWorld::refusal(app(), $own))->toBe([])
        ->and(FixtureAddonWorld::refusal(app(), FixtureAddonWorld::manifestWith(app(), [
            ...$contributions,
            new NavContribution(new ContributionId(FixtureAddonServiceProvider::ARTICLES_LINK), 'shell.nav@1', 'fixtureaddon.nav.articles_again', FixtureAddonServiceProvider::ARTICLES),
        ])))->toBe(['registry_panel_duplicate_contribution'])
        ->and(FixtureAddonWorld::refusal(app(), FixtureAddonWorld::manifestWith(app(), acceptsExperimental: array_values(array_diff(FixtureAddonServiceProvider::ACCEPTS_EXPERIMENTAL, ['login.notice@1', 'panel.observe.command@1'])))))->toBe(['registry_panel_experimental_not_accepted']);
});
