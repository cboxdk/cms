<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\HookError;
use Cbox\Cms\Contracts\Hooks\HookErrors;
use Cbox\Cms\Contracts\Hooks\PlanView;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Pipeline\Domain\CommandHooks;
use Cbox\Cms\Core\Pipeline\Domain\Dto\BoundHook;
use Cbox\Cms\Core\Tests\Entries\ListedContent;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandHooks;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackValidate;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Panel\Tests\Access\AccessWorld;
use Cbox\Cms\Panel\Tests\Content\ContentWorld;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use Pest\Browser\Api\PendingAwaitablePage;

/*
 * The entry editor in the panel, in Chromium (PRD 5.4, 11.12, 13.4, GUARDRAILS 8 and 9), against
 * the build `composer panel:build` writes, this checkout's test database and Valkey, over
 * ContentWorld: an administrator who reaches the site's root and may run entry.create and
 * entry.revise, and the articles of ListedContent. The administrator opens the list of the
 * fixture article type, starts a new entry from it, chooses its place in the site in the tree,
 * gives it a title and saves with ⌘↵, which runs entry.create through the Inertia profile and
 * turns the page into the page of the entry, saved as its receipt says; edits the title and saves
 * with ⌘↵ again, which runs entry.revise at the version the page read, and finds the change after a
 * reload; opens an entry from the list by its title. A save a validate hook refuses shows the
 * hook's error at the title field and in the form's summary of errors; while a save runs the page says so; an
 * entry the person cannot read and a type the installation does not have show why, with the way
 * back to the list. Every page and state makes the shared assertions at the three widths of a
 * phone, a tablet and a desktop: the translated texts, an empty console, no script error, no axe
 * finding at any impact, every WCAG 2.2 AA rule, and no policy violation.
 *
 * With CMS_DOCS_SCREENSHOTS=1 the editor is also captured into docs/screenshots/entry-editor.png
 * and entry-editor-mobile.png, the images docs/users/content.md shows.
 */

const EDITOR_PASSWORD = 'correct horse battery staple';

/** The widths the pages are checked at: a phone, a tablet and a desktop. */
const EDITOR_WIDTHS = [390, 820, 1440];

/** The article type's list, below which its editor is. */
const ARTICLES = '/cms/content/app:fixture_article';

/** The control of the article's title, by the id EntryForm gives it below the editor's prefix. */
const TITLE_INPUT = '#entry-fixture_title';

/** What the validate hook of the refusing test says about a title, as the hook's own text. */
const EMBARGOED = 'The title names a source under embargo; leave the source out of the title.';

/** Holds every request of the page back by 1.5 seconds, so the editor's saving state can be seen. */
const EDITOR_SLOW_REQUESTS = <<<'JS'
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
 * Signs the administrator in and opens the list of the article type from the navigation.
 */
function openArticles(): PendingAwaitablePage
{
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    $page->type('email', AccessWorld::ADMIN_EMAIL)
        ->type('password', EDITOR_PASSWORD)
        ->click('button[type="submit"]');
    $page->assertPathIs('/cms');
    $page->click('nav a:has-text("Fixture article")');
    $page->assertPathIs(ARTICLES);

    return $page;
}

/**
 * Makes the shared assertions at the three widths, and leaves the page at a desktop width.
 *
 * @param  list<string>  $texts
 */
function assertEditorPage(PendingAwaitablePage $page, array $texts): void
{
    foreach (EDITOR_WIDTHS as $width) {
        $page->resize($width, 900);
        PanelPage::assertPage($page, $texts);
    }

    $page->resize(1440, 900);
}

/**
 * Waits until the expression is true on the page.
 */
function editorWaitUntil(PendingAwaitablePage $page, string $expression): void
{
    expect(PanelProbe::eventually($page, $expression))->toBeTrue($expression);
}

/**
 * Waits until the status of the editor's header says the text.
 */
function waitForStatus(PendingAwaitablePage $page, string $text): void
{
    editorWaitUntil($page, 'document.querySelector(\'.cms-panel-editor__status\')?.textContent === '.json_encode($text, JSON_THROW_ON_ERROR));
}

/**
 * Replaces the article's title with the text.
 */
function retitle(PendingAwaitablePage $page, string $title): void
{
    $page->type(TITLE_INPUT, $title);
}

/**
 * Starts a new article from the list's create action, chooses the site's root as its place and
 * gives it the title.
 */
function startArticle(PendingAwaitablePage $page, string $title): void
{
    $page->click('main button:has-text("'.PanelPage::text('panel.content.create').'")');
    editorWaitUntil($page, 'location.pathname === '.json_encode(ARTICLES.'/new', JSON_THROW_ON_ERROR));
    $page->click('main button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->assertVisible('[role="treegrid"]');
    $page->keys('[role="treegrid"] [role="row"]:first-of-type', 'Space');
    $page->click('[role="dialog"]:has([role="treegrid"]) button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->assertMissing('[role="treegrid"]');
    retitle($page, $title);
}

/**
 * The editor's address of the entry the page shows, from the address it is at.
 */
function editorEntry(PendingAwaitablePage $page): string
{
    $path = $page->script('location.pathname');

    return is_string($path) ? substr($path, strlen(ARTICLES) + 1) : '';
}

/**
 * Saves the page as docs/screenshots/<key>.png when CMS_DOCS_SCREENSHOTS is set.
 */
function captureEditorScreenshot(PendingAwaitablePage $page, string $key, int $width, int $height): void
{
    if (getenv('CMS_DOCS_SCREENSHOTS') !== '1') {
        return;
    }

    $page->resize($width, $height)->screenshot(false, 'docs-'.$key);
    copy(Codebase::root().'/tests/Browser/Screenshots/docs-'.$key.'.png', Codebase::root().'/docs/screenshots/'.$key.'.png');
    $page->resize(1440, 900);
}

/**
 * The texts of the editor of an entry of the article type.
 *
 * @return list<string>
 */
function editorTexts(string ...$more): array
{
    return array_values(['panel.editor.save', 'panel.editor.back', 'panel.content.title', 'panel.home.sign_out', ...$more]);
}

beforeEach(function (): void {
    $world = new ContentWorld(app(), seed: 37, fixtureBuild: false);

    app(LocalCredentialStore::class)->bind($world->access->admin, new LoginIdentifier(AccessWorld::ADMIN_EMAIL), app(PasswordHasher::class)->hash(new Password(EDITOR_PASSWORD)));
});

it('creates an article and saves it with ⌘↵, then edits and saves it again at the version it read', function (): void {
    $page = openArticles();

    $page->click('main button:has-text("'.PanelPage::text('panel.content.create').'")');
    editorWaitUntil($page, 'location.pathname === '.json_encode(ARTICLES.'/new', JSON_THROW_ON_ERROR));
    $page->assertSee(PanelPage::text('panel.editor.new_title', ['type' => 'Fixture article']));
    assertEditorPage($page, editorTexts('panel.editor.state.new', 'panel.editor.home', 'panel.editor.home_description'));

    $page->click('main button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->assertVisible('[role="treegrid"]');
    $page->keys('[role="treegrid"] [role="row"]:first-of-type', 'Space');
    $page->click('[role="dialog"]:has([role="treegrid"]) button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->assertMissing('[role="treegrid"]');
    retitle($page, 'Rowing at dawn');
    $page->keys(TITLE_INPUT, 'Meta+Enter');

    editorWaitUntil($page, '/^\/cms\/content\/app:fixture_article\/[0-9a-f]{8}-[0-9a-f]{4}-7/.test(location.pathname)');
    waitForStatus($page, PanelPage::text('panel.editor.status.saved'));
    $entry = editorEntry($page);

    expect($page->script('document.querySelector("h1")?.textContent'))->toBe('Rowing at dawn')
        ->and($page->script('document.querySelector('.json_encode(TITLE_INPUT, JSON_THROW_ON_ERROR).')?.value'))->toBe('Rowing at dawn');
    $page->assertSee(PanelPage::text('panel.content.state.unreleased'));
    assertEditorPage($page, editorTexts('panel.editor.status.saved', 'panel.content.state.unreleased'));

    retitle($page, 'Rowing at dawn, and coffee after');
    $page->keys(TITLE_INPUT, 'Meta+Enter');
    editorWaitUntil($page, 'document.querySelector("h1")?.textContent === "Rowing at dawn, and coffee after" && document.querySelector(\'.cms-panel-editor__status\')?.textContent === '.json_encode(PanelPage::text('panel.editor.status.saved'), JSON_THROW_ON_ERROR));

    captureEditorScreenshot($page, 'entry-editor', 1280, 900);
    captureEditorScreenshot($page, 'entry-editor-mobile', 390, 900);

    $page->navigate(ARTICLES.'/'.$entry);
    editorWaitUntil($page, 'document.querySelector('.json_encode(TITLE_INPUT, JSON_THROW_ON_ERROR).')?.value === "Rowing at dawn, and coffee after"');

    expect($page->script('document.querySelector(\'.cms-panel-editor__status\')?.textContent'))->toBe('');

    $page->navigate(ARTICLES);
    $page->click('main [role="grid"] a:has-text("Rowing at dawn, and coffee after")');
    editorWaitUntil($page, 'location.pathname === '.json_encode(ARTICLES.'/'.$entry, JSON_THROW_ON_ERROR));
});

it('opens an entry of the list by its title and saves a change with the button', function (): void {
    $page = openArticles();
    $title = ListedContent::title(ListedContent::ARTICLES);

    $page->click('main [role="grid"] a:has-text("'.$title.'")');
    editorWaitUntil($page, 'location.pathname === '.json_encode(ARTICLES.'/'.ListedContent::id(ListedContent::ARTICLES), JSON_THROW_ON_ERROR));
    editorWaitUntil($page, 'document.querySelector('.json_encode(TITLE_INPUT, JSON_THROW_ON_ERROR).')?.value === '.json_encode($title, JSON_THROW_ON_ERROR));
    $page->assertSee(PanelPage::text('panel.content.state.released'));
    assertEditorPage($page, editorTexts('panel.content.state.released'));

    retitle($page, $title.' (corrected)');
    $page->click('main button:has-text("'.PanelPage::text('panel.editor.save').'")');
    waitForStatus($page, PanelPage::text('panel.editor.status.saved'));

    $page->navigate(ARTICLES.'/'.ListedContent::id(ListedContent::ARTICLES));
    editorWaitUntil($page, 'document.querySelector('.json_encode(TITLE_INPUT, JSON_THROW_ON_ERROR).')?.value === '.json_encode($title.' (corrected)', JSON_THROW_ON_ERROR));
});

it('shows a save the server refuses with each error at its field and in the summary of errors', function (): void {
    $refuse = new CallbackValidate(static function (PlanView $plan): HookErrors {
        foreach ($plan->revisions() as $revision) {
            $title = $revision->fields->own->get(new FieldHandle('fixture_title'));

            if ($title instanceof TextValue && str_contains($title->value, 'embargo')) {
                return new HookErrors(new HookError(EMBARGOED, new FieldHandle('fixture_title')));
            }
        }

        return HookErrors::none();
    });
    $hooks = new FakeCommandHooks;

    foreach (['entry.create', 'entry.revise'] as $command) {
        $hooks->add(new CommandName($command), 1, new BoundHook($refuse, 'acme/embargo', Phase::Validate, 0, 20));
    }

    app()->instance(CommandHooks::class, $hooks);
    $page = openArticles();
    startArticle($page, 'Notes from the embargo');
    $page->keys(TITLE_INPUT, 'Control+Enter');

    waitForStatus($page, PanelPage::text('panel.editor.status.rejected'));
    $describedBy = $page->script('document.querySelector('.json_encode(TITLE_INPUT, JSON_THROW_ON_ERROR).')?.getAttribute("aria-describedby") ?? ""');
    $errorText = $page->script('('.json_encode(is_string($describedBy) ? $describedBy : '', JSON_THROW_ON_ERROR).').split(" ").map((id) => document.getElementById(id)?.textContent ?? "").join(" ")');

    expect($errorText)->toContain(EMBARGOED)
        ->and($page->script('location.pathname'))->toBe(ARTICLES.'/new')
        ->and($page->script('document.querySelector('.json_encode(TITLE_INPUT, JSON_THROW_ON_ERROR).')?.getAttribute("aria-invalid")'))->toBe('true');
    $page->assertSee(PanelPage::kitText('kit.entry_form.summary_item', ['label' => 'Title', 'message' => EMBARGOED]));
    assertEditorPage($page, editorTexts('panel.editor.status.rejected'));

    retitle($page, 'Notes from the harbour');
    $page->keys(TITLE_INPUT, 'Control+Enter');
    editorWaitUntil($page, '/^\/cms\/content\/app:fixture_article\/[0-9a-f]{8}-/.test(location.pathname)');
    waitForStatus($page, PanelPage::text('panel.editor.status.saved'));
    $page->assertDontSee(EMBARGOED);
});

it('says that it saves while a save runs', function (): void {
    $page = openArticles();
    startArticle($page, 'A slow morning');

    $page->script(EDITOR_SLOW_REQUESTS);
    $page->keys(TITLE_INPUT, 'Meta+Enter');
    waitForStatus($page, PanelPage::text('panel.editor.status.saving'));

    expect($page->script('document.querySelector(\'main button[aria-busy="true"]\')?.disabled'))->toBeTrue();
    PanelPage::assertPage($page, editorTexts('panel.editor.status.saving', 'panel.editor.saving'));

    editorWaitUntil($page, '/^\/cms\/content\/app:fixture_article\/[0-9a-f]{8}-/.test(location.pathname)');
    waitForStatus($page, PanelPage::text('panel.editor.status.saved'));
});

it('shows an entry the person cannot read and a type the installation does not have with why and the way back', function (): void {
    $page = openArticles();

    $page->navigate(ARTICLES.'/0192a0c0-0000-7000-8000-0000000009e9');
    editorWaitUntil($page, 'document.body.textContent?.includes("entry_not_found") === true');
    assertEditorPage($page, ['panel.editor.title', 'panel.editor.back', 'panel.home.sign_out']);

    expect($page->script('document.querySelector(\'main form input\')'))->toBeNull();

    $page->click('main a:has-text("'.PanelPage::text('panel.editor.back').'")');
    editorWaitUntil($page, 'location.pathname === '.json_encode(ARTICLES, JSON_THROW_ON_ERROR));

    $page->navigate('/cms/content/app:fixture_notice/new');
    editorWaitUntil($page, 'document.body.textContent?.includes('.json_encode(PanelPage::text('panel.editor.localized_title'), JSON_THROW_ON_ERROR).') === true');
    assertEditorPage($page, ['panel.editor.localized_title', 'panel.editor.localized_body', 'panel.editor.localized_action', 'panel.editor.back']);
});
