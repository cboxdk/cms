<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorProfile;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\DisplayName;
use Cbox\Cms\Contracts\Identity\EmailAddress;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\AccessContexts;
use Cbox\Cms\Core\Identity\Domain\Commands\RegisterActor;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\FixtureWriters\Identity\Adapter\PostgresIdentitySeeder;
use Cbox\Cms\Testkit\FixtureWriters\Structure\Adapter\PostgresStructureFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Postgres\PartitionFixtures;
use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use Cbox\Cms\Tests\Support\Browser\PanelProbe;
use DateInterval;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use LogicException;
use Pest\Browser\Api\PendingAwaitablePage;

/*
 * The generic command form in Chromium (GUARDRAILS 2.2, 8 and 9; PRD 6.1, 8.4, 13.4), against the
 * build `composer panel:build` writes, this checkout's test database and Valkey: an administrator
 * whose role may register and activate actors, written by the testkit's fixture writers, registers
 * a pending member of staff through the register action over the real pipeline, signs in, opens
 * the command palette with the keyboard, chooses "Activate an actor" and lands on the form the
 * panel rendered from the schema of actor.activate, labelled by the catalogue. A value the schema
 * refuses is caught in the browser before anything is sent and shown at its field; the id of an
 * actor that is not pending is refused by the kernel with validation_failed, which the form shows
 * as the problem and at the field, from the errors prop; a dry run shows the dry_run receipt and
 * what would change while the actor stays pending; and a commit shows committed and the actor is
 * active. Every state makes the shared page assertions, the form at the three widths of a phone,
 * a tablet and a desktop: the translated texts, an empty console, no script error, no axe finding
 * at any impact, every WCAG 2.2 AA rule, and no policy violation.
 *
 * With CMS_DOCS_SCREENSHOTS=1 the form is also captured into docs/screenshots/command-form.png and
 * command-form-mobile.png, the images docs/addons/command-form.md shows.
 */

const FORM_ADMIN_EMAIL = 'ask.vinter@example.com';

const FORM_ADMIN_NAME = 'Ask Vinter';

const FORM_PASSWORD = 'correct horse battery staple';

const FORM_ROLE = 'formadmin';

/** The widths the form is checked at: a phone, a tablet and a desktop. */
const FORM_WIDTHS = [390, 820, 1440];

/**
 * The visible input of the version field. A number field keeps its name on a hidden input that
 * holds the number, as React Aria lays it out, so the field is found through it.
 */
const FORM_VERSION_INPUT = '.cms-field:has(input[name="version"]) input.cms-input';

/**
 * The actors of the test, which beforeEach writes for each test: the administrator and the
 * pending member of staff the administrator registered.
 *
 * @param  array{admin: ActorId, pending: ActorId}|null  $actors
 * @return array{admin: ActorId, pending: ActorId}
 */
function formActors(?array $actors = null): array
{
    /** @var array{admin: ActorId, pending: ActorId}|null $current */
    static $current = null;

    if ($actors !== null) {
        $current = $actors;
    }

    return $current ?? throw new LogicException('No actors.');
}

/**
 * Signs the administrator in and lands on the start page.
 */
function signInToForm(): PendingAwaitablePage
{
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    $page->type('email', FORM_ADMIN_EMAIL)
        ->type('password', FORM_PASSWORD)
        ->click('button[type="submit"]');
    $page->assertPathIs('/cms');

    return $page;
}

/**
 * Opens the palette with Ctrl+K, chooses the command's entry and lands on its form.
 */
function openActivateForm(PendingAwaitablePage $page): void
{
    $page->keys('body:first-of-type', 'Control+k');
    $page->assertVisible('[role="dialog"]');
    $page->type('input[type="search"]', 'actor.activate');

    expect(PanelProbe::eventually($page, 'document.querySelectorAll(\'[role="dialog"] [role="option"]\').length === 1'))->toBeTrue();

    $page->keys('input[type="search"]', 'Enter');
    $page->assertPathIs('/cms/commands/actor.activate/v1')->assertMissing('[role="dialog"]');
}

/**
 * The state and version of the actor, as the superuser reads them: "<state> <version>".
 */
function actorRow(ActorId $actor): string
{
    return StorageTables::texts(StorageTables::superuser(), 'select concat_ws(\' \', state, version) as value from actors where id = ?', [$actor->toString()])[0] ?? '';
}

/**
 * Saves the page as docs/screenshots/<key>.png when CMS_DOCS_SCREENSHOTS is set.
 */
function captureFormScreenshot(PendingAwaitablePage $page, string $key, int $width, int $height): void
{
    if (getenv('CMS_DOCS_SCREENSHOTS') !== '1') {
        return;
    }

    $page->resize($width, $height)->screenshot(false, 'docs-'.$key);
    copy(Codebase::root().'/tests/Browser/Screenshots/docs-'.$key.'.png', Codebase::root().'/docs/screenshots/'.$key.'.png');
}

beforeEach(function (): void {
    $clock = new FakeClock;
    $ids = new FakeIdGenerator(seed: 29, clock: $clock);
    $connections = app(ConnectionResolverInterface::class);
    $identity = new PostgresIdentitySeeder($connections, $clock, $ids);
    $access = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, $ids);
    $root = new PostgresStructureFixtures($connections, $clock, $ids)->site('form', [new Locale('da')])->root->id;
    $admin = $identity->addActor(ActorClass::Staff, ActorState::Active)->id;

    // The registration below and the form's commits run through the application's pipeline at the
    // application's clock, so the partitioned tables get partitions there first.
    app(PartitionFixtures::class)->coverClock(app(Clock::class), new DateInterval('P2D'));

    // The administrator's profile, role and grant are written by the testkit's fixture writers as
    // the owner role: a role that may register and activate actors on the site's root.
    $identity->addProfile($admin, new ActorProfile(new DisplayName(FORM_ADMIN_NAME), new EmailAddress(FORM_ADMIN_EMAIL)));
    $access->grant($admin, $access->role(FORM_ROLE, ClassificationAccess::Personal, [new CommandName('actor.register'), new CommandName('actor.activate')]), $root);
    app(LocalCredentialStore::class)->bind($admin, new LoginIdentifier(FORM_ADMIN_EMAIL), app(PasswordHasher::class)->hash(new Password(FORM_PASSWORD)));

    // The pending member of staff is registered through the register action, as the administrator,
    // over the real pipeline, so the form's activation is the real second step of a registration.
    $pending = new ActorId($ids->next());
    $principal = new ActorPrincipal($admin, [], IssuerKind::Service, ClassificationAccess::Sensitive);
    $result = app(CommandPipeline::class)->run(new CommandCall(
        new RegisterActor($pending, ActorClass::Staff, new DisplayName('Bo Lind'), new EmailAddress('bo.lind@example.com')),
        Envelope::external(IssuingSurface::Cli, EnvelopeIssuer::Human, $admin, new IdempotencyKey('command-form-register'), new CorrelationId('command-form-test')),
        app(AccessContexts::class)->for($principal),
    ));

    expect($result->errors)->toBe([])
        ->and(actorRow($pending))->toBe('pending 1');

    formActors(['admin' => $admin, 'pending' => $pending]);
});

it('opens the form from the palette, labelled by the catalogue, and keeps a value the schema refuses from being sent', function (): void {
    $page = signInToForm();

    openActivateForm($page);
    PanelPage::assertPage($page, [
        'panel.action.actor.activate.title',
        'panel.action.actor.activate.description',
        'panel.action.actor.activate.field.actor.label',
        'panel.action.actor.activate.field.actor.description',
        'panel.action.actor.activate.field.version.label',
        'panel.command_form.options',
        'panel.command_form.dry_run',
        'panel.command_form.wait_level',
        'panel.command_form.run',
    ]);

    foreach (FORM_WIDTHS as $width) {
        $page->resize($width, 900);
        PanelPage::assertPage($page, ['panel.action.actor.activate.title', 'panel.command_form.run']);
    }

    captureFormScreenshot($page, 'command-form-mobile', 390, 900);
    $page->resize(1440, 900);

    // A planted invalid value: the generated validator refuses it in the browser, at its field,
    // before anything is sent.
    $page->type('actor', 'not-an-id')
        ->type(FORM_VERSION_INPUT, '1')
        ->click(PanelPage::text('panel.command_form.run'));

    $page->assertSee(PanelPage::text('panel.command_form.invalid_title'));
    expect(PanelProbe::eventually($page, 'document.querySelector(\'input[name="actor"]\')?.getAttribute(\'aria-invalid\') === "true"'))->toBeTrue()
        ->and(StorageTables::texts(StorageTables::superuser(), 'select count(*)::text as value from changesets where command = ?', ['actor.activate']))->toBe(['0']);
    PanelPage::assertPage($page, ['panel.command_form.invalid_title']);
});

it('shows the kernel\'s refusal at its field, a dry run\'s receipt and what would change, and the committed receipt once the actor is activated', function (): void {
    ['admin' => $admin, 'pending' => $pending] = formActors();
    $page = signInToForm();

    openActivateForm($page);

    // The administrator's own id is well-formed, but the actor is active, not pending: the kernel
    // refuses with validation_failed at the field, which the form shows from the errors prop.
    $page->type('actor', $admin->toString())
        ->type(FORM_VERSION_INPUT, '1')
        ->click(PanelPage::text('panel.command_form.run'));

    $page->assertSee(PanelPage::text('panel.command_form.refused_title'))
        ->assertSee('validation_failed')
        ->assertSee('Only a pending actor is activated');
    expect(PanelProbe::eventually($page, 'document.querySelector(\'input[name="actor"]\')?.getAttribute(\'aria-invalid\') === "true"'))->toBeTrue();
    PanelPage::assertPage($page, ['panel.command_form.refused_title', 'panel.host.refused']);

    // A dry run of the pending actor's activation commits nothing and shows what would change. The
    // dry run box is checked through its label, as a person checks it: the kit draws the box over
    // its native input.
    $page->type('actor', $pending->toString())
        ->click(PanelPage::text('panel.command_form.dry_run'))
        ->click(PanelPage::text('panel.command_form.try'));

    $page->assertSee('Dry run: nothing was saved')
        ->assertSee(PanelPage::text('panel.command_form.dry_run_report'))
        ->assertSee('actor:'.$pending->toString());
    expect(actorRow($pending))->toBe('pending 1');
    PanelPage::assertPage($page, ['panel.command_form.result', 'panel.command_form.dry_run_report']);
    captureFormScreenshot($page, 'command-form', 1024, 900);
    $page->resize(1440, 900);

    // The commit activates the actor at its next version and shows the committed receipt.
    $page->click(PanelPage::text('panel.command_form.dry_run'))
        ->click(PanelPage::text('panel.command_form.run'));

    $page->assertSee(PanelPage::text('panel.command_form.committed_title'))
        ->assertSee(PanelPage::text('panel.command_form.committed_body'));
    expect(PanelProbe::eventually($page, '(() => document.querySelector(\'.cms-receipt-status\')?.textContent?.includes("Saved"))()'))->toBeTrue()
        ->and(actorRow($pending))->toBe('active 2');
    PanelPage::assertPage($page, ['panel.command_form.committed_title']);
});
