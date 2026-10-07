<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Clock;
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
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use DateInterval;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Pest\Browser\Api\PendingAwaitablePage;
use Workbench\FixtureAddon\FixtureAddonServiceProvider;
use Workbench\FixtureAddon\FixtureArticle;

/*
 * The points of the generic command form in Chromium (PRD 13.4, section 8 of the panel extension
 * architecture), against the build `composer panel:build` writes, this checkout's test database
 * and Valkey, on the form of entry.create with the workbench's fixture addon contributing to it
 * from its signed bundle: an author whose role may create entries and list nodes signs in, opens
 * the form from the palette and finds the home node's input replaced by the core's node picker,
 * which lists the site's root from node.list. A title that derives no slug shows the addon's
 * warning at the title; a slug that is not well formed is the addon's blocking error, mirrored by
 * its hook RequireWellFormedSlug, so the run is held and nothing is sent; a slug set by hand asks
 * for the viewer's acknowledgement, and the run is held until it is ticked; then the addon's step
 * runs before the submit, stops the flow in the addon's name when the viewer stops it, and, run
 * again, takes the slug and goes on to the core's confirmation, which alone sends the draft. The
 * kernel refuses that slug, which is longer than the blueprint allows, and its error at the
 * field's path replaces the addon's acknowledged issue there, at the fields control, from the
 * errors prop. With a slug the title derives, the step, the confirmation and the commit follow,
 * and the article is written with the slug the step showed. Every state makes the shared page
 * assertions, the form with its issues at the three widths of a phone, a tablet and a desktop.
 *
 * The addon's texts show as their keys: an addon's catalogue is not loaded by the panel yet.
 */

const POINTS_EMAIL = 'ida.lund@example.com';

const POINTS_NAME = 'Ida Lund';

const POINTS_PASSWORD = 'correct horse battery staple';

const POINTS_ROLE = 'pointsauthor';

const POINTS_SITE = 'points';

/** The widths the form is checked at: a phone, a tablet and a desktop. */
const POINTS_WIDTHS = [390, 820, 1440];

/** A well-formed slug longer than the blueprint's max_length of 120, which the kernel refuses. */
const LONG_SLUG = 'a-quiet-week-that-goes-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on-and-on';

/**
 * The world of the test, which beforeEach writes for each test: the id the new entry gets.
 *
 * @param  array{entry: EntryId}|null  $world
 * @return array{entry: EntryId}
 */
function pointsWorld(?array $world = null): array
{
    /** @var array{entry: EntryId}|null $current */
    static $current = null;

    if ($world !== null) {
        $current = $world;
    }

    return $current ?? throw new LogicException('No world.');
}

/**
 * Signs the author in and lands on the start page.
 */
function signInToPoints(): PendingAwaitablePage
{
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    $page->type('email', POINTS_EMAIL)
        ->type('password', POINTS_PASSWORD)
        ->click('button[type="submit"]');
    $page->assertPathIs('/cms');

    return $page;
}

/**
 * Opens the palette with Ctrl+K, chooses entry.create and lands on its form.
 */
function openCreateForm(PendingAwaitablePage $page): void
{
    $page->keys('body:first-of-type', 'Control+k');
    $page->assertVisible('[role="dialog"]');
    $page->type('input[type="search"]', 'entry.create');

    expect(PanelProbe::eventually($page, 'document.querySelectorAll(\'[role="dialog"] [role="option"]\').length === 1'))->toBeTrue();

    $page->keys('input[type="search"]', 'Enter');
    $page->assertPathIs('/cms/commands/entry.create/v1')->assertMissing('[role="dialog"]');
}

/**
 * The fields of the revision as the form's JSON editor takes them.
 *
 * @param  array<string, mixed>  $fields
 */
function fieldsJson(array $fields): string
{
    return json_encode($fields, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
}

/**
 * Fills the fields of the revision: the title, the featured flag the blueprint requires, and the
 * addon's slug when given.
 */
function fillFields(PendingAwaitablePage $page, string $title, ?string $slug = null): void
{
    $fields = ['fixture_title' => $title, 'fixture_featured' => true];

    if ($slug !== null) {
        $fields['ext'] = ['fixtureaddon' => ['fixture_slug' => $slug]];
    }

    $page->fill('fields', fieldsJson($fields));
}

/**
 * The addon's slug of the one article written, as the superuser reads it, or null for none.
 */
function articleSlug(): ?string
{
    $rows = StorageTables::superuser()->table('app__fixture_article')->get(['ext__fixtureaddon__fixture_slug']);

    expect($rows)->toHaveCount(1);

    $slug = $rows->first()?->ext__fixtureaddon__fixture_slug;

    return is_string($slug) ? $slug : null;
}

function articleCount(): int
{
    return StorageTables::superuser()->table('app__fixture_article')->count();
}

beforeEach(function (): void {
    $clock = new FakeClock;
    $ids = new FakeIdGenerator(seed: 31, clock: $clock);
    $connections = app(ConnectionResolverInterface::class);
    $identity = new PostgresIdentitySeeder($connections, $clock, $ids);
    $access = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, $ids);
    $root = new PostgresStructureFixtures($connections, $clock, $ids)->site(POINTS_SITE, [new Locale('da')])->root->id;
    $author = $identity->addActor(ActorClass::Staff, ActorState::Active)->id;

    // The form's commits run through the application's pipeline at the application's clock, so
    // the partitioned tables get partitions there first.
    app(PartitionFixtures::class)->coverClock(app(Clock::class), new DateInterval('P2D'));

    // The author's profile, role and grant are written by the testkit's fixture writers as the
    // owner role: a role that may create entries on the site's root and list its nodes, which the
    // core's node picker reads.
    $identity->addProfile($author, new ActorProfile(new DisplayName(POINTS_NAME), new EmailAddress(POINTS_EMAIL)));
    $access->grant($author, $access->role(POINTS_ROLE, ClassificationAccess::Internal, [new CommandName('entry.create'), new CommandName('node.list')]), $root);
    app(LocalCredentialStore::class)->bind($author, new LoginIdentifier(POINTS_EMAIL), app(PasswordHasher::class)->hash(new Password(POINTS_PASSWORD)));

    pointsWorld(['entry' => new EntryId($ids->next())]);
});

it('shows a warning check, holds for an acknowledge check and a mirrored blocking check, runs a step that cancels and then patches the draft, replaces the home input with the core\'s node picker, and lets the kernel\'s error replace the acknowledged issue at its path', function (): void {
    $entry = pointsWorld()['entry'];
    $page = signInToPoints();

    openCreateForm($page);
    PanelPage::assertPage($page, [
        'panel.action.entry.create.title',
        'panel.action.entry.create.field.entry.label',
        'panel.action.entry.create.field.fields.label',
        'panel.command_form.run',
        'panel.command_form.try',
    ]);

    // The home node's input is the core's picker, the replacement at command.form.field@1 of the
    // member bound to NodeId, which lists the site's root from node.list as the author.
    $page->assertVisible('[data-cms-contribution="cms.node-picker"]');
    $page->click('[data-cms-contribution="cms.node-picker"] .cms-node-picker button');
    $page->assertVisible('[role="dialog"]');
    $page->click('[role="dialog"] .cms-tree__item:has-text("'.POINTS_SITE.'")');
    $page->click('[role="dialog"] button:has-text("'.PanelPage::kitText('kit.picker.choose').'")');
    $page->assertMissing('[role="dialog"]')
        ->assertSeeIn('[data-cms-contribution="cms.node-picker"]', POINTS_SITE);

    $page->type('entry', $entry->toString())
        ->type('type', FixtureArticle::TYPE_ID);

    // A title with no letter or digit derives no slug: the addon's warning, listed with its field,
    // blocks nothing.
    fillFields($page, '!!!');
    $page->assertSee('fixtureaddon.slug_hint.message')
        ->assertSee(PanelPage::text('panel.command_form.issue_title', ['addon' => FixtureAddonServiceProvider::NAMESPACE, 'field' => 'fields.fixture_title']));

    foreach (POINTS_WIDTHS as $width) {
        $page->resize($width, 900);
        PanelPage::assertPage($page, ['panel.command_form.issues', 'panel.command_form.go_to_field']);
    }

    $page->resize(1440, 900);

    // A slug that is not well formed is the addon's error, which mirrors its hook: the run is held
    // and nothing is sent.
    fillFields($page, 'A quiet week', 'Bad Slug');
    $page->assertSee('fixtureaddon.slug_shape.message')
        ->assertDontSee('fixtureaddon.slug_hint.message');
    $page->click(PanelPage::text('panel.command_form.run'));
    $page->assertSee(PanelPage::text('panel.command_form.held_title'));
    expect(articleCount())->toBe(0);

    // A slug set by hand, well formed but not the one the title derives, asks for an
    // acknowledgement: the run is held until it is ticked.
    fillFields($page, 'A quiet week', LONG_SLUG);
    $page->assertSee('fixtureaddon.slug_override.message')
        ->assertDontSee('fixtureaddon.slug_shape.message');
    $page->click(PanelPage::text('panel.command_form.run'));
    $page->assertSee(PanelPage::text('panel.command_form.held_title'));
    expect(articleCount())->toBe(0);

    $page->click(PanelPage::text('panel.command_form.acknowledge'));
    $page->click(PanelPage::text('panel.command_form.run'));

    // The addon's step runs before the submit; stopping it cancels the flow in the addon's name.
    $page->assertSee('fixtureaddon.slug_review.title')
        ->assertSee(PanelPage::text('panel.command_form.step_of', ['step' => 1, 'addon' => FixtureAddonServiceProvider::NAMESPACE]));
    PanelPage::assertPage($page, ['panel.command_form.flow']);
    $page->click('button:has-text("fixtureaddon.slug_review.stop")');
    $page->assertSee(PanelPage::text('panel.host.step_cancelled', ['addon' => FixtureAddonServiceProvider::NAMESPACE]))
        ->assertSee('fixtureaddon.slug_review.cancelled');
    expect(articleCount())->toBe(0);

    // Run again, the step takes the slug and the core's confirmation sends the draft; the kernel
    // refuses the slug, longer than the blueprint allows, and its error replaces the addon's
    // acknowledged issue at the same path, shown at the fields control.
    $page->click(PanelPage::text('panel.command_form.back'));
    $page->click(PanelPage::text('panel.command_form.run'));
    $page->click('button:has-text("fixtureaddon.slug_review.use")');
    $page->assertSee(PanelPage::text('panel.host.confirm_title'));
    $page->click('button:text-is("'.PanelPage::text('panel.host.confirm').'")');

    $page->assertSee(PanelPage::text('panel.command_form.refused_title'))
        ->assertSee('validation_failed')
        ->assertDontSee('fixtureaddon.slug_override.message');
    expect(PanelProbe::eventually($page, 'document.querySelector(\'textarea[name="fields"]\')?.getAttribute(\'aria-invalid\') === "true"'))->toBeTrue()
        ->and(articleCount())->toBe(0);
    PanelPage::assertPage($page, ['panel.command_form.refused_title', 'panel.host.refused']);

    // With a slug the title derives, nothing is asked: the step shows it, the confirmation sends
    // the draft, and the article is written with it.
    fillFields($page, 'A quiet week', 'a-quiet-week');
    $page->assertDontSee('fixtureaddon.slug_override.message');
    $page->click(PanelPage::text('panel.command_form.run'));
    $page->assertSee('fixtureaddon.slug_review.slug');
    $page->click('button:has-text("fixtureaddon.slug_review.use")');
    $page->click('button:text-is("'.PanelPage::text('panel.host.confirm').'")');

    $page->assertSee(PanelPage::text('panel.command_form.committed_title'))
        ->assertSee(PanelPage::text('panel.command_form.committed_body'));
    expect(PanelProbe::eventually($page, '(() => document.querySelector(\'.cms-receipt-status\')?.textContent?.includes("Saved"))()'))->toBeTrue()
        ->and(articleSlug())->toBe('a-quiet-week');
    PanelPage::assertPage($page, ['panel.command_form.committed_title']);
});
