<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Core\Tests\Entries\ListedContent;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Panel\Tests\Access\AccessWorld;
use Cbox\Cms\Panel\Tests\Content\ContentWorld;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use LogicException;
use Pest\Browser\Api\PendingAwaitablePage;

/*
 * The content list in the panel, in Chromium (PRD 5.4, 13.4, GUARDRAILS 8 and 9), against the build
 * `composer panel:build` writes, this checkout's test database and Valkey, over ContentWorld: an
 * administrator who reaches the site's root and may run entry.create, 27 articles of which the
 * two newest are released, one event and no measurement or notice. The administrator signs in and
 * finds the Content section in the navigation with an entry for every content and one per type of
 * the workbench, each named by its type's label; opens the list across every type, sees each entry
 * by its title field with its type, state and last change, pages through it with Next and
 * Previous, filters it by state and by type with the filter chips, both kept in the address,
 * switches between comfortable and compact rows, and finds the empty list of a type without
 * entries with the create action. While another page loads, the table says so; a read the query
 * pipeline rejects shows the problem details with what the code means. Every page and state makes
 * the shared assertions at the three widths of a phone, a tablet and a desktop: the translated
 * texts, an empty console, no script error, no axe finding at any impact, every WCAG 2.2 AA rule,
 * and no policy violation.
 *
 * With CMS_DOCS_SCREENSHOTS=1 the list is also captured into docs/screenshots/content-list.png
 * and content-list-mobile.png, the images docs/users/content.md shows.
 */

const CONTENT_PASSWORD = 'correct horse battery staple';

/** The widths the pages are checked at: a phone, a tablet and a desktop. */
const CONTENT_WIDTHS = [390, 820, 1440];

/** Holds every request of the page back by 1.5 seconds, so the table's loading state can be seen. */
const SLOW_REQUESTS = <<<'JS'
    (() => {
        const send = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function (...args) {
            setTimeout(() => send.apply(this, args), 1500);
        };
        const fetch = window.fetch;
        window.fetch = (...args) => new Promise((resolve) => setTimeout(resolve, 1500)).then(() => fetch(...args));
        return true;
    })()
    JS;

/**
 * The world of the test, which beforeEach writes for each test.
 */
function contentWorld(?ContentWorld $world = null): ContentWorld
{
    /** @var ContentWorld|null $current */
    static $current = null;

    if ($world instanceof ContentWorld) {
        $current = $world;
    }

    return $current ?? throw new LogicException('No content world.');
}

/**
 * Signs the administrator in and opens the content list across every type from the navigation.
 */
function openContent(): PendingAwaitablePage
{
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    $page->type('email', AccessWorld::ADMIN_EMAIL)
        ->type('password', CONTENT_PASSWORD)
        ->click('button[type="submit"]');
    $page->assertPathIs('/cms');
    $page->click('nav a:has-text("'.PanelPage::text('panel.nav.content_all').'")');
    $page->assertPathIs('/cms/content');

    return $page;
}

/**
 * Makes the shared assertions at the three widths, and leaves the page at a desktop width.
 *
 * @param  list<string>  $texts
 */
function assertContentPage(PendingAwaitablePage $page, array $texts): void
{
    foreach (CONTENT_WIDTHS as $width) {
        $page->resize($width, 900);
        PanelPage::assertPage($page, $texts);
    }

    $page->resize(1440, 900);
}

/**
 * Waits until the expression is true on the page.
 */
function contentWaitUntil(PendingAwaitablePage $page, string $expression): void
{
    expect(PanelProbe::eventually($page, $expression))->toBeTrue($expression);
}

/**
 * The titles of the rows the table shows, in order.
 *
 * @return list<string>
 */
function contentTitles(PendingAwaitablePage $page): array
{
    $titles = $page->script('[...document.querySelectorAll(\'[role="grid"] tbody .cms-row-title__title\')].map((title) => title.textContent)');

    return is_array($titles) ? array_values(array_filter($titles, is_string(...))) : [];
}

/**
 * Chooses the option with the text in the menu of the filter chip that names what it filters by.
 */
function chooseFilter(PendingAwaitablePage $page, string $chip, string $option): void
{
    $page->click('.cms-filter-chip__trigger:has-text("'.$chip.'")');
    $page->assertVisible('[role="menu"]');
    $page->click('[role="menu"] [role="menuitem"]:has-text("'.$option.'")');
}

/**
 * Saves the page as docs/screenshots/<key>.png when CMS_DOCS_SCREENSHOTS is set.
 */
function captureContentScreenshot(PendingAwaitablePage $page, string $key, int $width, int $height): void
{
    if (getenv('CMS_DOCS_SCREENSHOTS') !== '1') {
        return;
    }

    $page->resize($width, $height)->screenshot(false, 'docs-'.$key);
    copy(Codebase::root().'/tests/Browser/Screenshots/docs-'.$key.'.png', Codebase::root().'/docs/screenshots/'.$key.'.png');
    $page->resize(1440, 900);
}

/**
 * The texts of the list at every width; the last change is left out on a phone, so it is checked
 * at a desktop's width alone.
 *
 * @return list<string>
 */
function contentTexts(string ...$more): array
{
    return array_values(['panel.content.column.title', 'panel.content.column.state', 'panel.content.filter.type', 'panel.content.filter.state', 'panel.content.density.comfortable', 'panel.content.density.compact', 'panel.home.sign_out', ...$more]);
}

beforeEach(function (): void {
    $world = contentWorld(new ContentWorld(app(), seed: 31, fixtureBuild: false));

    app(LocalCredentialStore::class)->bind($world->access->admin, new LoginIdentifier(AccessWorld::ADMIN_EMAIL), app(PasswordHasher::class)->hash(new Password(CONTENT_PASSWORD)));
});

it('gives every content and each type of the workbench an entry in the Content section, and lists the entries by their titles, a page at a time', function (): void {
    $page = openContent();

    $page->assertSee(PanelPage::text('panel.shell.content'))->assertSee(PanelPage::text('panel.nav.content_all'));
    assertContentPage($page, contentTexts('panel.content.title', 'panel.content.description', 'panel.content.create', 'panel.content.state.released', 'panel.content.state.unreleased'));

    $entries = $page->script('[...document.querySelectorAll(\'nav [aria-labelledby] a\')].map((link) => [link.textContent, link.getAttribute(\'href\')])');

    expect($entries)->toBe([
        [PanelPage::text('panel.nav.content_all'), '/cms/content'],
        ['Fixture article', '/cms/content/app:fixture_article'],
        ['Fixture event', '/cms/content/app:fixture_event'],
        ['Fixture measurement', '/cms/content/app:fixture_measurement'],
        ['Fixture notice', '/cms/content/app:fixture_notice'],
    ])
        ->and($page->script('document.querySelector(\'nav a[aria-current="page"]\')?.textContent'))->toBe(PanelPage::text('panel.nav.content_all'))
        ->and(contentTitles($page)[0] ?? null)->toBe(ListedContent::title(ListedContent::ARTICLES))
        ->and(contentTitles($page))->toHaveCount(25);
    $page->assertSee('Fixture article')->assertSee(PanelPage::text('panel.content.column.changed'));

    $page->resize(390, 900);
    $page->assertDontSee(PanelPage::text('panel.content.column.changed'));
    $page->resize(1440, 900);

    captureContentScreenshot($page, 'content-list', 1280, 800);
    captureContentScreenshot($page, 'content-list-mobile', 390, 800);

    $page->click('button:has-text("'.PanelPage::kitText('kit.pagination.next').'")');
    contentWaitUntil($page, 'location.search.startsWith("?after=")');
    contentWaitUntil($page, 'document.body.textContent?.includes('.json_encode(ListedContent::EVENT_NAME, JSON_THROW_ON_ERROR).') === true');

    expect(contentTitles($page))->toBe([ListedContent::title(2), ListedContent::title(1), ListedContent::EVENT_NAME]);
    $page->assertSee('Fixture event');
    assertContentPage($page, contentTexts());

    $page->click('button:has-text("'.PanelPage::kitText('kit.pagination.previous').'")');
    contentWaitUntil($page, 'location.search === ""');
    contentWaitUntil($page, 'document.querySelector(\'[role="grid"] tbody .cms-row-title__title\')?.textContent === '.json_encode(ListedContent::title(ListedContent::ARTICLES), JSON_THROW_ON_ERROR));
});

it('filters the list by state and by type with the filter chips, kept in the address, and switches the density', function (): void {
    $page = openContent();

    chooseFilter($page, PanelPage::text('panel.content.filter.state'), PanelPage::text('panel.content.state.released'));
    contentWaitUntil($page, 'location.search === "?state=released"');
    contentWaitUntil($page, 'document.querySelectorAll(\'[role="grid"] tbody tr\').length === 2');

    expect(contentTitles($page))->toBe([ListedContent::title(ListedContent::ARTICLES), ListedContent::title(ListedContent::ARTICLES - 1)]);
    assertContentPage($page, contentTexts('panel.content.state.released'));

    chooseFilter($page, PanelPage::text('panel.content.filter.type'), 'Fixture article');
    contentWaitUntil($page, 'location.pathname === "/cms/content/app:fixture_article" && location.search === "?state=released"');

    expect($page->script('document.querySelector(\'nav a[aria-current="page"]\')?.textContent'))->toBe('Fixture article')
        ->and(contentTitles($page))->toHaveCount(2);

    $page->click('button[aria-label="'.PanelPage::kitText('kit.filter_chip.clear', ['label' => PanelPage::text('panel.content.filter.state')]).'"]');
    contentWaitUntil($page, 'location.pathname === "/cms/content/app:fixture_article" && location.search === ""');
    contentWaitUntil($page, 'document.querySelectorAll(\'[role="grid"] tbody tr\').length === 25');

    $page->click('button:has-text("'.PanelPage::text('panel.content.density.compact').'")');
    contentWaitUntil($page, 'document.querySelector(\'.cms-data-table\')?.dataset.density === "compact"');
    PanelPage::assertPage($page, contentTexts('panel.content.column.type'));
    $page->click('button:has-text("'.PanelPage::text('panel.content.density.comfortable').'")');
    contentWaitUntil($page, 'document.querySelector(\'.cms-data-table\')?.dataset.density === "comfortable"');

    $page->click('button[aria-label="'.PanelPage::kitText('kit.filter_chip.clear', ['label' => PanelPage::text('panel.content.filter.type')]).'"]');
    contentWaitUntil($page, 'location.pathname === "/cms/content"');
});

it('shows the empty list of a type without entries with the create action', function (): void {
    $page = openContent();
    $page->click('nav a:has-text("Fixture measurement")');

    $page->assertPathIs('/cms/content/app:fixture_measurement');
    assertContentPage($page, contentTexts('panel.content.empty_title', 'panel.content.empty_body', 'panel.content.create'));

    expect($page->script('document.querySelectorAll(\'main button\').length'))->toBeGreaterThan(1)
        ->and($page->script('document.querySelector(\'nav a[aria-current="page"]\')?.textContent'))->toBe('Fixture measurement');

    $page->click('main [role="grid"] button:has-text("'.PanelPage::text('panel.content.create').'")');
    contentWaitUntil($page, 'location.pathname.startsWith("/cms/commands/entry.create/v")');
});

it('says what it waits for while another page loads', function (): void {
    $page = openContent();

    $page->script(SLOW_REQUESTS);
    $page->click('button:has-text("'.PanelPage::kitText('kit.pagination.next').'")');
    contentWaitUntil($page, 'document.querySelector(\'[role="grid"] [role="status"]\')?.textContent?.includes('.json_encode(PanelPage::text('panel.content.loading'), JSON_THROW_ON_ERROR).') === true');

    PanelPage::assertPage($page, contentTexts('panel.content.loading'));

    contentWaitUntil($page, 'document.querySelector(\'[role="grid"] [role="status"]\') === null && location.search.startsWith("?after=")');
});

it('shows a read the query pipeline rejects with the problem details and what to do', function (): void {
    config()->set('cbox-cms.queries.budgets.actor', 10);
    $page = openContent();

    assertContentPage($page, ['panel.content.title', 'panel.content.refused', 'panel.content.filter.type', 'panel.home.sign_out']);
    $page->assertSee('query_over_budget');

    expect($page->script('document.querySelector(\'[role="grid"]\')'))->toBeNull();
});
