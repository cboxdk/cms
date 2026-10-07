<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Tests\Support\Browser\FixtureAddonWorld;
use Cbox\Cms\Tests\Support\Browser\Interactions;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use Pest\Browser\Api\PendingAwaitablePage;
use Workbench\FixtureAddon\FixtureArticle;

/*
 * The Interaction to Next Paint of the panel with every point of block B1 populated (section 7 of
 * the panel extension architecture, GUARDRAILS 10), in Chromium against the build
 * `composer panel:build` writes: the workbench's fixture addon contributes to every point, and an
 * editor who gets every contribution works through the panel with the keyboard and the mouse,
 * the login page with its notice, the start page's palette, the who-am-I page's sections with
 * their data, the addon's page, the grants and roles pages with their notes, and the form of
 * entry.create with its aside, decorators, checks on every edit, dry run section and step, and
 * the form of the addon's own command with the addon's input of its own value class. Every
 * interaction, from the input to the next paint, takes less than 200 ms, read with the Event
 * Timing API (Interactions), observed from before the first interaction of each document; the
 * login is a full page load, so its document is observed on its own.
 */

/**
 * Opens the palette with the keyboard, filters it and closes it: the first Escape clears what was
 * typed, the second closes the palette.
 */
function inpPalette(PendingAwaitablePage $page, string $text): void
{
    $page->keys('body:first-of-type', 'Control+k');
    $page->assertVisible('[role="dialog"]');
    $page->type('input[type="search"]', $text);
    $page->keys('input[type="search"]', 'Escape');
    $page->keys('input[type="search"]', 'Escape');
    $page->assertMissing('[role="dialog"]');
}

beforeEach(function (): void {
    FixtureAddonWorld::seed(app(), 43);
});

it('keeps every interaction under 200 ms with every B1 point populated', function (): void {
    $entry = new EntryId(new FakeIdGenerator(seed: 99)->next());

    // The login page, a document of its own: the notice is shown, and typing is an interaction.
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    PanelPage::assertPage($page, ['panel.login.title']);
    $page->assertSee('fixtureaddon.login_notice.message');
    Interactions::observe($page);
    $page->type('email', FixtureAddonWorld::EDITOR_EMAIL)
        ->type('password', FixtureAddonWorld::PASSWORD);
    Interactions::assertUnder($page);

    $page->click('button[type="submit"]');
    $page->assertPathIs('/cms');

    // The pages behind the login, one document through Inertia's visits: the palette, the
    // who-am-I page with the addon's sections and their data, the addon's page, the grants and
    // roles pages, and the form of entry.create with every point of the form.
    PanelPage::assertPage($page, ['panel.home.body']);
    Interactions::observe($page);
    inpPalette($page, 'who');

    $page->click('nav a:has-text("'.PanelPage::text('panel.nav.account_me').'")');
    $page->assertPathIs('/cms/account/me');
    $page->assertSee('fixtureaddon.recent_activity.title')
        ->assertSee('fixtureaddon.articles.none_title');
    PanelPage::assertPage($page, ['panel.account_me.title']);

    $page->click('nav a:has-text("fixtureaddon.nav.articles")');
    $page->assertPathIs('/cms/x/fixtureaddon/articles');
    $page->assertSee('fixtureaddon.articles.none_title');
    $page->assertSee('fixtureaddon.articles.page_title');
    PanelPage::assertPage($page, ['panel.home.sign_out']);

    $page->click('nav a:has-text("'.PanelPage::text('panel.nav.grants').'")');
    $page->assertPathIs('/cms/access/grants');
    $page->assertSee('fixtureaddon.four_eyes_note.title');
    PanelPage::assertPage($page, ['panel.grants.title']);

    $page->click('nav a:has-text("'.PanelPage::text('panel.nav.roles').'")');
    $page->assertPathIs('/cms/access/roles');
    $page->assertSee('fixtureaddon.articles_permission.title');
    PanelPage::assertPage($page, ['panel.roles.title']);

    // The addon's own command from the palette: typing into the addon's input shapes a slug.
    $page->keys('body:first-of-type', 'Control+k');
    $page->assertVisible('[role="dialog"]');
    $page->type('input[type="search"]', 'slug.set');
    expect(PanelProbe::eventually($page, 'document.querySelectorAll(\'[role="dialog"] [role="option"]\').length === 1'))->toBeTrue();
    $page->keys('input[type="search"]', 'Enter');
    $page->assertPathIs('/cms/commands/fixtureaddon.slug.set/v1');
    $page->assertVisible('[data-cms-contribution="fixtureaddon.slug-input"] input[name="slug"]');
    $page->type('slug', 'A Quiet Week');
    $page->assertSee('fixtureaddon.slug_input.description');
    PanelPage::assertPage($page, ['panel.command_form.run']);

    expect($page->script('document.querySelector("[data-fixtureaddon-slug-preview]")?.dataset.fixtureaddonSlugPreview'))->toBe('a-quiet-week');

    $page->click('button:has-text("fixtureaddon.new_article.label")');
    $page->assertPathIs('/cms/commands/entry.create/v1');
    $page->assertSee('fixtureaddon.slug_help.title')
        ->assertSee('fixtureaddon.submit_note.description');

    $page->click('[data-cms-contribution="cms.node-picker"] .cms-node-picker button');
    $page->assertVisible('[role="dialog"]');
    $page->click('[role="dialog"] .cms-tree__item:has-text("'.FixtureAddonWorld::SITE.'")');
    $page->click('[role="dialog"] button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->assertMissing('[role="dialog"]');
    $page->type('entry', $entry->toString())
        ->type('type', FixtureArticle::TYPE_ID);

    // Each edit of the fields runs the checks: a title that derives no slug warns, a slug set by
    // hand asks to acknowledge, a well-formed one shows nothing.
    $page->fill('fields', json_encode(['fixture_title' => '!!!', 'fixture_featured' => true], JSON_THROW_ON_ERROR));
    $page->assertSee('fixtureaddon.slug_hint.message');
    $page->fill('fields', json_encode(['fixture_title' => 'A quiet week', 'fixture_featured' => true, 'ext' => ['fixtureaddon' => ['fixture_slug' => 'quiet-week']]], JSON_THROW_ON_ERROR));
    $page->assertSee('fixtureaddon.slug_override.message');
    $page->click(PanelPage::text('panel.command_form.acknowledge'));
    $page->fill('fields', json_encode(['fixture_title' => 'A quiet week', 'fixture_featured' => true], JSON_THROW_ON_ERROR));
    $page->assertDontSee('fixtureaddon.slug_override.message');

    // The dry run and the step, up to the core's confirmation, which is not pressed.
    $page->click(PanelPage::text('panel.command_form.try'));
    $page->assertSee('fixtureaddon.dry_run_note.title');
    $page->click(PanelPage::text('panel.command_form.run'));
    $page->assertSee('fixtureaddon.slug_review.slug');
    $page->click('button:has-text("fixtureaddon.slug_review.use")');
    $page->assertSee(PanelPage::text('panel.host.confirm_title'));
    PanelPage::assertPage($page, ['panel.command_form.flow']);

    expect(PanelProbe::eventually($page, 'document.querySelectorAll("[data-cms-contribution]").length > 0'))->toBeTrue();
    Interactions::assertUnder($page);
});
