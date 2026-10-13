<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Tests\Entries\EntryFields;
use Cbox\Cms\Core\Tests\Entries\ListedContent;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Panel\Tests\Access\AccessWorld;
use Cbox\Cms\Panel\Tests\Content\ContentWorld;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use Illuminate\Database\DatabaseManager;
use Pest\Browser\Api\PendingAwaitablePage;

/*
 * The entry editor's conflicts, its leave guard and its local recovery, in Chromium (PRD 5.4, 6.1,
 * 13.4, GUARDRAILS 8 and 9), against the build `composer panel:build` writes, this checkout's test
 * database and Valkey, over ContentWorld: the administrator Ada, who reaches the site's root, may
 * run entry.revise and actor.list and reads personal data, so she may read the profiles of the
 * staff, and Ole Frost, who is granted the editor role on the root here and saves from another
 * connection, the real command pipeline in the test's own process.
 *
 * Ada edits an article while Ole saves it: her save is refused with version_conflict, and the page
 * says Ole Frost saved it, compares the title he saved with hers, takes his version into the form
 * with her edit beside it to bring back, and saves hers at his version. With changes she has not
 * saved, a visit inside the panel asks whether to leave, stays, and leaves and discards them on
 * request, and closing or reloading the tab asks the browser. After a reload with changes she had
 * not saved, the editor offers them back, restores them, and offers nothing on another entry, or
 * on the same entry once Ole saved a new version of it. Every state makes the shared assertions
 * at the three widths of a phone, a tablet and a desktop: the translated texts, an empty console,
 * no script error, no axe finding at any impact, every WCAG 2.2 AA rule, no policy violation and
 * no sideways scroll.
 *
 * With CMS_DOCS_SCREENSHOTS=1 the conflict is also captured into
 * docs/screenshots/entry-editor-conflict.png, the image docs/users/content.md shows.
 */

const CONFLICT_PASSWORD = 'correct horse battery staple';

/** The widths the pages are checked at: a phone, a tablet and a desktop. */
const CONFLICT_WIDTHS = [390, 820, 1440];

/** The article type's list, below which its editor is. */
const CONFLICT_ARTICLES = '/cms/content/app:fixture_article';

/** The control of the article's title, by the id EntryForm gives it below the editor's prefix. */
const CONFLICT_TITLE = '#entry-fixture_title';

/** The article type's entry in the Content section of the navigation, a visit inside the panel. */
const SIDE_NAV_ARTICLES = 'nav a.cms-side-nav__link:has-text("Fixture article")';

/** The prefix of the editor's recovery records in the browser's storage. */
const RECOVERY_PREFIX = 'cms.panel.editor.recovery.v1:';

/**
 * Ole, granted the editor role on the site's root, which may run entry.revise.
 */
function grantOle(ContentWorld $world): void
{
    $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), app(Clock::class), new FakeIdGenerator(seed: 4242, clock: app(Clock::class)));
    $fixtures->grant($world->access->ole, $world->access->editor, $world->access->root);
}

/**
 * Ole saves the article with the title from another connection: entry.revise through the real
 * pipeline at the version the shared head has now, as an editor of another browser would.
 */
function oleSaves(ContentWorld $world, int $n, string $title): void
{
    $version = StorageTables::superuser()->table('variant_heads')->where('entry_id', ListedContent::id($n))->where('variant', 'shared')->value('version');
    $principal = new ActorPrincipal($world->access->ole, [], IssuerKind::Service, ClassificationAccess::Sensitive);
    $result = app(CommandPipeline::class)->run(new CommandCall(
        new ReviseEntry(EntryId::fromString(ListedContent::id($n)), new AggregateVersion(is_int($version) ? $version : 0), EntryFields::article($title)),
        Envelope::external(IssuingSurface::Cli, EnvelopeIssuer::Human, $world->access->ole, new IdempotencyKey('ole-'.$n.'-'.md5($title)), new CorrelationId('entry-editor-conflict')),
        app(AccessContexts::class)->for($principal),
    ));

    expect($result->receipt->outcome)->toBe(Outcome::Committed);
}

/**
 * Signs Ada in and opens the editor of the article.
 */
function openArticle(int $n): PendingAwaitablePage
{
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    $page->type('email', AccessWorld::ADMIN_EMAIL)
        ->type('password', CONFLICT_PASSWORD)
        ->click('button[type="submit"]');
    $page->assertPathIs('/cms');
    $page->navigate(CONFLICT_ARTICLES.'/'.ListedContent::id($n));
    conflictWaitUntil($page, 'document.querySelector('.json_encode(CONFLICT_TITLE, JSON_THROW_ON_ERROR).')?.value === '.json_encode(ListedContent::title($n), JSON_THROW_ON_ERROR));

    return $page;
}

/**
 * Waits until the expression is true on the page.
 */
function conflictWaitUntil(PendingAwaitablePage $page, string $expression): void
{
    expect(PanelProbe::eventually($page, $expression))->toBeTrue($expression);
}

/**
 * The value of the article's title in the form.
 */
function titleValue(PendingAwaitablePage $page): mixed
{
    return $page->script('document.querySelector('.json_encode(CONFLICT_TITLE, JSON_THROW_ON_ERROR).')?.value');
}

/**
 * Makes the shared assertions at the three widths, and leaves the page at a desktop width.
 *
 * @param  array<non-negative-int|string, string|array<string, string|int>>  $texts
 */
function assertConflictPage(PendingAwaitablePage $page, array $texts): void
{
    foreach (CONFLICT_WIDTHS as $width) {
        $page->resize($width, 900);
        PanelPage::assertPage($page, $texts);
    }

    $page->resize(1440, 900);
}

/**
 * Whether the page asks the browser before it unloads: a cancelable beforeunload event the
 * editor cancels.
 */
function asksBeforeUnload(PendingAwaitablePage $page): mixed
{
    return $page->script('(() => { const event = new Event("beforeunload", { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; })()');
}

/**
 * Opens the palette with Ctrl+K, finds the page by its label and opens it with Enter, a visit
 * through Inertia's router.
 */
function paletteVisit(PendingAwaitablePage $page, string $label): void
{
    $page->keys('body:first-of-type', 'Control+k');
    conflictWaitUntil($page, 'document.activeElement?.matches(\'input[type="search"]\') === true');
    $page->type('input[type="search"]', $label);
    $page->keys('input[type="search"]', 'Enter');
}

/**
 * How many recovery records of the editor the browser keeps.
 */
function recoveryRecords(PendingAwaitablePage $page): mixed
{
    return $page->script('Object.keys(localStorage).filter((key) => key.startsWith('.json_encode(RECOVERY_PREFIX, JSON_THROW_ON_ERROR).')).length');
}

beforeEach(function (): void {
    $world = new ContentWorld(app(), seed: 41, fixtureBuild: false);

    app(LocalCredentialStore::class)->bind($world->access->admin, new LoginIdentifier(AccessWorld::ADMIN_EMAIL), app(PasswordHasher::class)->hash(new Password(CONFLICT_PASSWORD)));
    grantOle($world);
    app()->instance(ContentWorld::class, $world);
});

it('shows who saved in between, compares per field, and saves the edit at the version they saved', function (): void {
    $world = app(ContentWorld::class);
    $n = ListedContent::ARTICLES;
    $original = ListedContent::title($n);
    $page = openArticle($n);

    $page->type(CONFLICT_TITLE, $original.' (mine)');
    oleSaves($world, $n, $original.' (by Ole)');
    $page->keys(CONFLICT_TITLE, 'Control+Enter');

    conflictWaitUntil($page, 'document.body.textContent?.includes('.json_encode(PanelPage::text('panel.editor.conflict.title'), JSON_THROW_ON_ERROR).') === true');
    $page->assertSee(AccessWorld::OLE_NAME.' saved it at');

    expect(titleValue($page))->toBe($original.' (mine)')
        ->and($page->script('document.querySelector(".cms-panel-editor__status")?.textContent'))->toBe(PanelPage::text('panel.editor.status.rejected'))
        ->and($page->script('[...document.querySelectorAll(".cms-panel-editor__difference")].map((item) => item.textContent)'))->toBe([
            'Title'.PanelPage::text('panel.editor.conflict.theirs', ['name' => AccessWorld::OLE_NAME]).$original.' (by Ole)'.PanelPage::text('panel.editor.conflict.mine').$original.' (mine)',
        ]);
    assertConflictPage($page, [
        'panel.editor.conflict.title',
        'panel.editor.conflict.compare',
        'panel.editor.conflict.theirs' => ['name' => AccessWorld::OLE_NAME],
        'panel.editor.conflict.mine',
        'panel.editor.conflict.reload',
    ]);

    if (getenv('CMS_DOCS_SCREENSHOTS') === '1') {
        $page->resize(1280, 900)->screenshot(false, 'docs-entry-editor-conflict');
        copy(Codebase::root().'/tests/Browser/Screenshots/docs-entry-editor-conflict.png', Codebase::root().'/docs/screenshots/entry-editor-conflict.png');
        $page->resize(1440, 900);
    }

    $page->click('main button:has-text("'.PanelPage::text('panel.editor.conflict.reload').'")');
    conflictWaitUntil($page, 'document.querySelector('.json_encode(CONFLICT_TITLE, JSON_THROW_ON_ERROR).')?.value === '.json_encode($original.' (by Ole)', JSON_THROW_ON_ERROR));
    $page->assertDontSee(PanelPage::text('panel.editor.conflict.title'));
    assertConflictPage($page, [
        'panel.editor.compare.title',
        'panel.editor.compare.body' => ['name' => AccessWorld::OLE_NAME],
        'panel.editor.compare.use_mine',
        'panel.editor.compare.use_all',
    ]);

    $page->click('main button[aria-label="'.PanelPage::text('panel.editor.compare.use_mine').': Title"]');
    conflictWaitUntil($page, 'document.querySelector('.json_encode(CONFLICT_TITLE, JSON_THROW_ON_ERROR).')?.value === '.json_encode($original.' (mine)', JSON_THROW_ON_ERROR));
    $page->keys(CONFLICT_TITLE, 'Control+Enter');
    conflictWaitUntil($page, 'document.querySelector(".cms-panel-editor__status")?.textContent === '.json_encode(PanelPage::text('panel.editor.status.saved'), JSON_THROW_ON_ERROR));

    $page->navigate(CONFLICT_ARTICLES.'/'.ListedContent::id($n));
    conflictWaitUntil($page, 'document.querySelector('.json_encode(CONFLICT_TITLE, JSON_THROW_ON_ERROR).')?.value === '.json_encode($original.' (mine)', JSON_THROW_ON_ERROR));

    expect(recoveryRecords($page))->toBe(0);
});

it('asks before a visit in the panel away from changes that are not saved, stays, and leaves and discards them on request', function (): void {
    $n = ListedContent::ARTICLES - 2;
    $page = openArticle($n);
    $editor = CONFLICT_ARTICLES.'/'.ListedContent::id($n);

    expect(asksBeforeUnload($page))->toBeFalse();

    $page->type(CONFLICT_TITLE, 'Left unsaved');

    expect(asksBeforeUnload($page))->toBeTrue();

    // A visit through Inertia's router, as the palette makes it, asks in the page's own dialog.
    paletteVisit($page, PanelPage::text('panel.nav.roles'));
    $page->assertVisible('[role="alertdialog"]');

    expect($page->script('location.pathname'))->toBe($editor);
    assertConflictPage($page, ['panel.editor.leave.title', 'panel.editor.leave.body', 'panel.editor.leave.confirm', 'panel.editor.leave.cancel']);

    $page->click('[role="alertdialog"] button:has-text("'.PanelPage::text('panel.editor.leave.cancel').'")');
    $page->assertMissing('[role="alertdialog"]');

    expect($page->script('location.pathname'))->toBe($editor)
        ->and(titleValue($page))->toBe('Left unsaved')
        ->and(recoveryRecords($page))->toBe(1);

    paletteVisit($page, PanelPage::text('panel.nav.roles'));
    $page->click('[role="alertdialog"] button:has-text("'.PanelPage::text('panel.editor.leave.confirm').'")');
    conflictWaitUntil($page, 'location.pathname !== '.json_encode($editor, JSON_THROW_ON_ERROR));

    expect(recoveryRecords($page))->toBe(0);
});

it('asks the browser before a link loads another page over changes that are not saved', function (): void {
    $n = ListedContent::ARTICLES - 2;
    $page = openArticle($n);

    $page->type(CONFLICT_TITLE, 'Left unsaved');
    // A listener added after the editor's sees whether the editor asked the browser to confirm,
    // which Playwright answers by leaving; the page after it reads what the listener noted.
    $page->script('window.addEventListener("beforeunload", (event) => { sessionStorage.setItem("cms.test.asked", String(event.defaultPrevented)); })');
    $page->click(SIDE_NAV_ARTICLES);
    conflictWaitUntil($page, 'location.pathname === '.json_encode(CONFLICT_ARTICLES, JSON_THROW_ON_ERROR));

    expect($page->script('sessionStorage.getItem("cms.test.asked")'))->toBe('true')
        ->and(recoveryRecords($page))->toBe(1);
});

it('offers the edits a reload interrupted for that entry and version only, and restores them', function (): void {
    $world = app(ContentWorld::class);
    $n = ListedContent::ARTICLES - 3;
    $other = ListedContent::ARTICLES - 4;
    $page = openArticle($n);

    $page->type(CONFLICT_TITLE, 'Kept through a reload');
    $page->refresh();
    conflictWaitUntil($page, 'document.body.textContent?.includes('.json_encode(PanelPage::text('panel.editor.recovery.title'), JSON_THROW_ON_ERROR).') === true');

    expect(titleValue($page))->toBe(ListedContent::title($n));
    assertConflictPage($page, ['panel.editor.recovery.title', 'panel.editor.recovery.restore', 'panel.editor.recovery.discard']);

    $page->click('main button:has-text("'.PanelPage::text('panel.editor.recovery.restore').'")');
    conflictWaitUntil($page, 'document.querySelector('.json_encode(CONFLICT_TITLE, JSON_THROW_ON_ERROR).')?.value === "Kept through a reload"');
    $page->assertDontSee(PanelPage::text('panel.editor.recovery.title'));

    $page->navigate(CONFLICT_ARTICLES.'/'.ListedContent::id($other));
    conflictWaitUntil($page, 'document.querySelector('.json_encode(CONFLICT_TITLE, JSON_THROW_ON_ERROR).')?.value === '.json_encode(ListedContent::title($other), JSON_THROW_ON_ERROR));
    $page->assertDontSee(PanelPage::text('panel.editor.recovery.title'));

    expect(recoveryRecords($page))->toBe(1);

    oleSaves($world, $n, 'A new version by Ole');
    $page->navigate(CONFLICT_ARTICLES.'/'.ListedContent::id($n));
    conflictWaitUntil($page, 'document.querySelector('.json_encode(CONFLICT_TITLE, JSON_THROW_ON_ERROR).')?.value === "A new version by Ole"');
    $page->assertDontSee(PanelPage::text('panel.editor.recovery.title'));

    expect(recoveryRecords($page))->toBe(0);
});
