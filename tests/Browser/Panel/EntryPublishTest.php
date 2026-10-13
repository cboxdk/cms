<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Panel\Tests\Access\AccessWorld;
use Cbox\Cms\Panel\Tests\Content\ContentWorld;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Pest\Browser\Api\PendingAwaitablePage;

/*
 * Publishing and previewing in the entry editor, in Chromium (PRD 5.4, 5.6, 5.7, 8.9, 13.4,
 * GUARDRAILS 8 and 9), against the build `composer panel:build` writes, this checkout's test
 * database and Valkey, over ContentWorld with publishing: an administrator who reaches the root of
 * the site `content`, served at content.example, and may run the entry commands and those of the
 * publish panel. The administrator creates an article, places it below the site's root with a slug
 * and a language, and publishes it: the dry run names the placement's path as what becomes visible,
 * and once confirmed GET /v1/resolve answers 200 with the released fields. A change saved after
 * that shows in the Preview, the record entry.preview writes as the delivery API would, while
 * /v1/resolve still shows the released state; the Preview shows the released revision when it is
 * chosen, and reads at another width and language. A window scheduled for later leaves the
 * placement scheduled and the address unanswered, and an unpublish closes it again. Every state
 * makes the shared assertions at the three widths of a phone, a tablet and a desktop: the
 * translated texts, an empty console, no script error, no axe finding at any impact, every WCAG 2.2
 * AA rule, and no policy violation.
 *
 * With CMS_DOCS_SCREENSHOTS=1 the publish panel and the Preview are also captured into
 * docs/screenshots/entry-publish.png and entry-preview.png, the images docs/users/content.md shows.
 */

const PUBLISH_PASSWORD = 'correct horse battery staple';

/** The widths the pages are checked at: a phone, a tablet and a desktop. */
const PUBLISH_WIDTHS = [390, 820, 1440];

/** The article type's list, below which its editor is. */
const PUBLISH_ARTICLES = '/cms/content/app:fixture_article';

/** The control of the article's title, by the id EntryForm gives it below the editor's prefix. */
const PUBLISH_TITLE = '#entry-fixture_title';

/** The host the site of ContentWorld is served at. */
const PUBLISH_HOST = 'content.example';

/**
 * Makes the shared assertions at the three widths, and leaves the page at a desktop width.
 *
 * @param  list<string>  $texts
 */
function assertPublishPage(PendingAwaitablePage $page, array $texts): void
{
    foreach (PUBLISH_WIDTHS as $width) {
        $page->resize($width, 900);
        PanelPage::assertPage($page, $texts);
    }

    $page->resize(1440, 900);
}

/**
 * Waits until the expression is true on the page.
 */
function publishWaitUntil(PendingAwaitablePage $page, string $expression): void
{
    expect(PanelProbe::eventually($page, $expression))->toBeTrue($expression);
}

/**
 * Waits until the page's text holds the text.
 */
function publishWaitForText(PendingAwaitablePage $page, string $text): void
{
    publishWaitUntil($page, 'document.body.textContent?.includes('.json_encode($text, JSON_THROW_ON_ERROR).') === true');
}

/**
 * The text of the open dialog, or an empty string.
 */
function dialogText(PendingAwaitablePage $page): string
{
    $text = $page->script('document.querySelector(\'[role="dialog"]\')?.textContent ?? ""');

    return is_string($text) ? $text : '';
}

/**
 * Signs the administrator in, starts a new article from the list, places its home at the site's
 * root, gives it the title and saves it with ⌘↵, and waits for the page of the entry.
 */
function createArticle(string $title): PendingAwaitablePage
{
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    $page->type('email', AccessWorld::ADMIN_EMAIL)
        ->type('password', PUBLISH_PASSWORD)
        ->click('button[type="submit"]');
    $page->assertPathIs('/cms');
    $page->navigate(PUBLISH_ARTICLES.'/new');
    publishWaitUntil($page, 'document.querySelector('.json_encode(PUBLISH_TITLE, JSON_THROW_ON_ERROR).') !== null');
    $page->click('main button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->keys('[role="treegrid"] [role="row"]:first-of-type', 'Space');
    $page->click('[role="dialog"]:has([role="treegrid"]) button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->assertMissing('[role="treegrid"]');
    $page->type(PUBLISH_TITLE, $title);
    $page->keys(PUBLISH_TITLE, 'Meta+Enter');
    publishWaitUntil($page, '/^\/cms\/content\/app:fixture_article\/[0-9a-f]{8}-[0-9a-f]{4}-7/.test(location.pathname)');
    publishWaitForText($page, PanelPage::text('panel.editor.status.saved'));

    return $page;
}

/**
 * Opens the tab of the editor with the label.
 */
function openTab(PendingAwaitablePage $page, string $label): void
{
    $page->click('[role="tab"]:has-text("'.$label.'")');
    publishWaitUntil($page, 'document.querySelector(\'[role="tab"][aria-selected="true"]\')?.textContent === '.json_encode($label, JSON_THROW_ON_ERROR));
}

/**
 * Places the entry below the site's root with the slug in Danish, from the publish panel.
 */
function placeAtRoot(PendingAwaitablePage $page, string $slug): void
{
    $page->click('main form:has(#cms-panel-publish-slug) button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->keys('[role="treegrid"] [role="row"]:first-of-type', 'Space');
    $page->click('[role="dialog"]:has([role="treegrid"]) button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->assertMissing('[role="treegrid"]');
    $page->type('#cms-panel-publish-slug', $slug);
    $page->type('#cms-panel-publish-locale', 'da');
    $page->click('main button:has-text("'.PanelPage::text('panel.publish.place').'")');
    publishWaitForText($page, 'content/'.$slug);
}

/**
 * The button of the row of the path in Danish, by the label the panel gives it.
 */
function rowButton(string $key, string $path): string
{
    return 'main button[aria-label="'.PanelPage::text($key, ['path' => $path, 'locale' => 'da']).'"]';
}

/**
 * The visibility the placement of the path has in Danish, as its row's badge says it.
 */
function rowVisibility(PendingAwaitablePage $page, string $path): string
{
    $text = $page->script('[...document.querySelectorAll(".cms-panel-publish__row")].find((row) => row.querySelector("code")?.textContent === '.json_encode($path, JSON_THROW_ON_ERROR).')?.textContent ?? ""');

    return is_string($text) ? $text : '';
}

/**
 * The answer of GET /v1/resolve for the path on the site in Danish, through the HTTP kernel of the
 * application the browser is served by: its status and its document.
 *
 * @return array{int, array<string, mixed>}
 */
function resolveAnswer(string $path): array
{
    $request = Request::create('/v1/resolve?'.http_build_query(['site' => PUBLISH_HOST, 'locale' => 'da', 'path' => $path]));
    $response = app(HttpKernel::class)->handle($request);
    $document = json_decode((string) $response->getContent(), true, 64, JSON_THROW_ON_ERROR);
    $fields = [];

    foreach (is_array($document) ? $document : [] as $key => $value) {
        $fields[(string) $key] = $value;
    }

    return [$response->getStatusCode(), $fields];
}

/**
 * Saves the page as docs/screenshots/<key>.png when CMS_DOCS_SCREENSHOTS is set.
 */
function capturePublishScreenshot(PendingAwaitablePage $page, string $key): void
{
    if (getenv('CMS_DOCS_SCREENSHOTS') !== '1') {
        return;
    }

    $page->resize(1280, 1000)->screenshot(false, 'docs-'.$key);
    copy(Codebase::root().'/tests/Browser/Screenshots/docs-'.$key.'.png', Codebase::root().'/docs/screenshots/'.$key.'.png');
    $page->resize(1440, 900);
}

beforeEach(function (): void {
    config()->set('cbox-cms.sites', ['content' => ['origin' => 'https://'.PUBLISH_HOST, 'locales' => ['da', 'en']]]);
    $world = new ContentWorld(app(), seed: 41, fixtureBuild: false, publishing: true);

    app(LocalCredentialStore::class)->bind($world->access->admin, new LoginIdentifier(AccessWorld::ADMIN_EMAIL), app(PasswordHasher::class)->hash(new Password(PUBLISH_PASSWORD)));
});

it('creates, places and publishes an article after a dry run that names the placement, which /v1/resolve then answers', function (): void {
    $page = createArticle('Rowing at dawn');

    openTab($page, PanelPage::text('panel.editor.tab.publish'));
    $page->assertSee(PanelPage::text('panel.publish.no_placements'));
    assertPublishPage($page, ['panel.publish.title', 'panel.publish.unreleased', 'panel.publish.no_placements', 'panel.publish.place_title']);

    placeAtRoot($page, 'rowing-at-dawn');
    expect(rowVisibility($page, 'content/rowing-at-dawn'))->toContain(PanelPage::text('panel.publish.visibility.hidden'));

    $page->click(rowButton('panel.publish.publish_label', 'content/rowing-at-dawn'));
    publishWaitUntil($page, 'document.querySelector(\'[role="dialog"]\') !== null');

    expect(dialogText($page))->toContain(PanelPage::text('panel.publish.becomes_visible_item', ['path' => 'content/rowing-at-dawn', 'locale' => 'da']));
    assertPublishPage($page, ['panel.publish.review_intro', 'panel.publish.becomes_visible', 'panel.publish.confirm', 'panel.publish.cancel']);

    $page->click('[role="dialog"] button:has-text("'.PanelPage::text('panel.publish.confirm').'")');
    publishWaitUntil($page, '[...document.querySelectorAll(".cms-panel-publish__row")].some((row) => row.textContent?.includes('.json_encode(PanelPage::text('panel.publish.visibility.live'), JSON_THROW_ON_ERROR).'))');

    [$status, $document] = resolveAnswer('/rowing-at-dawn');
    $data = is_array($document['data'] ?? null) ? $document['data'] : [];

    expect($status)->toBe(200)
        ->and($data['fixture_title'] ?? null)->toBe('Rowing at dawn');
    $page->assertSee(PanelPage::text('panel.publish.released', ['revision' => 2]));
    assertPublishPage($page, ['panel.publish.title', 'panel.publish.visibility.live', 'panel.publish.unpublish']);
    capturePublishScreenshot($page, 'entry-publish');
});

it('shows an unreleased change in the Preview while /v1/resolve still shows the released state', function (): void {
    $page = createArticle('Harbour at noon');

    openTab($page, PanelPage::text('panel.editor.tab.publish'));
    placeAtRoot($page, 'harbour');
    $page->click(rowButton('panel.publish.publish_label', 'content/harbour'));
    publishWaitUntil($page, 'document.querySelector(\'[role="dialog"]\') !== null');
    $page->click('[role="dialog"] button:has-text("'.PanelPage::text('panel.publish.confirm').'")');
    publishWaitUntil($page, '[...document.querySelectorAll(".cms-panel-publish__row")].some((row) => row.textContent?.includes('.json_encode(PanelPage::text('panel.publish.visibility.live'), JSON_THROW_ON_ERROR).'))');

    openTab($page, PanelPage::text('panel.editor.tab.content'));
    $page->type(PUBLISH_TITLE, 'Harbour at noon, with gulls');
    $page->keys(PUBLISH_TITLE, 'Meta+Enter');
    publishWaitUntil($page, 'document.querySelector("h1")?.textContent === "Harbour at noon, with gulls"');

    openTab($page, PanelPage::text('panel.editor.tab.preview'));
    publishWaitUntil($page, 'document.querySelector(".cms-panel-preview__title")?.textContent === "Harbour at noon, with gulls"');
    $page->assertSee(PanelPage::text('panel.preview.showing_draft', ['released' => 2]));

    [$status, $document] = resolveAnswer('/harbour');
    $data = is_array($document['data'] ?? null) ? $document['data'] : [];

    expect($status)->toBe(200)
        ->and($data['fixture_title'] ?? null)->toBe('Harbour at noon');
    assertPublishPage($page, ['panel.preview.title', 'panel.preview.revision', 'panel.preview.width.reading', 'panel.preview.record']);
    capturePublishScreenshot($page, 'entry-preview');

    $page->click('main [aria-label="'.PanelPage::text('panel.preview.width').'"] button:has-text("'.PanelPage::text('panel.preview.width.phone').'")');
    publishWaitUntil($page, 'document.querySelector(".cms-panel-preview__frame")?.dataset.width === "phone"');
    $page->click('main [aria-label="'.PanelPage::text('panel.preview.locale').'"] button:text-is("da")');
    publishWaitUntil($page, 'document.querySelector(".cms-panel-preview__document")?.lang === "da"');
    assertPublishPage($page, ['panel.preview.title', 'panel.preview.width.phone']);

    $page->click('#cms-panel-preview-revision');
    $page->click('[role="option"]:has-text("'.PanelPage::text('panel.preview.revision_released', ['revision' => 2]).'")');
    publishWaitUntil($page, 'new URLSearchParams(location.search).get("revision") === "2" && document.querySelector(".cms-panel-preview__title")?.textContent === "Harbour at noon"');
    $page->assertSee(PanelPage::text('panel.preview.showing_released'));
    assertPublishPage($page, ['panel.preview.title', 'panel.preview.showing_released']);
});

it('schedules a window for later, which leaves the address unanswered, and unpublishes', function (): void {
    $page = createArticle('Regatta');

    openTab($page, PanelPage::text('panel.editor.tab.publish'));
    placeAtRoot($page, 'regatta');

    $page->click(rowButton('panel.publish.schedule_label', 'content/regatta'));
    publishWaitUntil($page, 'document.querySelector(\'[role="dialog"] input[type="datetime-local"]\') !== null');
    assertPublishPage($page, ['panel.publish.schedule_from', 'panel.publish.schedule_until', 'panel.publish.schedule_review']);
    $page->click('[role="dialog"] button:has-text("'.PanelPage::text('panel.publish.schedule_review').'")');
    publishWaitForText($page, PanelPage::text('panel.publish.schedule_missing'));

    $page->type('[role="dialog"] input[type="datetime-local"] >> nth=0', date('Y-m-d\TH:i', time() + 3 * 86400));
    $page->click('[role="dialog"] button:has-text("'.PanelPage::text('panel.publish.schedule_review').'")');
    publishWaitUntil($page, 'document.querySelector(\'[role="dialog"]\')?.textContent?.includes('.json_encode(PanelPage::text('panel.publish.review_intro'), JSON_THROW_ON_ERROR).') === true');
    $page->click('[role="dialog"] button:has-text("'.PanelPage::text('panel.publish.confirm').'")');
    publishWaitUntil($page, '[...document.querySelectorAll(".cms-panel-publish__row")].some((row) => row.textContent?.includes('.json_encode(PanelPage::text('panel.publish.visibility.scheduled'), JSON_THROW_ON_ERROR).'))');

    expect(resolveAnswer('/regatta')[0])->toBe(404);
    assertPublishPage($page, ['panel.publish.visibility.scheduled', 'panel.publish.live_from', 'panel.publish.unpublish']);

    $page->click('main button:has-text("'.PanelPage::text('panel.publish.unpublish').'")');
    publishWaitUntil($page, 'document.querySelector(\'[role="dialog"]\')?.textContent?.includes('.json_encode(PanelPage::text('panel.publish.review_unpublish'), JSON_THROW_ON_ERROR).') === true');
    $page->click('[role="dialog"] button:has-text("'.PanelPage::text('panel.publish.confirm').'")');
    publishWaitForText($page, PanelPage::text('panel.publish.unreleased'));

    expect(rowVisibility($page, 'content/regatta'))->toContain(PanelPage::text('panel.publish.visibility.hidden'))
        ->and(resolveAnswer('/regatta')[0])->toBe(404);
});
