<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Contracts\Identity\LocalCredentialStore;
use Cbox\Cms\Contracts\Identity\LoginIdentifier;
use Cbox\Cms\Contracts\Identity\Password;
use Cbox\Cms\Core\Pipeline\Actions\CommandPipeline;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyId;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyTable;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyWorld;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Identity\LocalAccounts\Domain\PasswordHasher;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\Contributions\ShellWorld;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use LogicException;
use Pest\Browser\Api\PendingAwaitablePage;
use stdClass;

/*
 * An addon's action in the panel, in Chromium (PRD 13.4, section 3.3 of the panel extension
 * architecture), against the build `composer panel:build` writes, this checkout's test database
 * and Valkey: the test addon of ContributionWorld contributes to the shell's user menu the action
 * ADD, which runs the test-only command tally.add for the viewer's own tally after a dry run. A
 * member of staff who holds tally.add signs in, sees the action by its label, presses it, reviews
 * what the dry run found, which committed nothing, confirms, and sees the receipt of the commit;
 * the command ran through the Inertia profile as the person, the real pipeline over TallyWorld
 * committed it, and the changeset records the provenance of the contribution. A viewer who does
 * not hold the command's permission is never shown the action.
 */

const ACTIONS_EMAIL = 'nina.bruun@example.com';

const ACTIONS_PASSWORD = 'correct horse battery staple';

const ACTION_PROVENANCE = 'addon:tally:'.ContributionWorld::ADD;

/**
 * The world of the test-only command, which beforeEach makes for each test.
 */
function tallyWorld(?TallyWorld $world = null): TallyWorld
{
    /** @var TallyWorld|null $current */
    static $current = null;

    if ($world instanceof TallyWorld) {
        $current = $world;
    }

    return $current instanceof TallyWorld ? $current : throw new LogicException('No tally world.');
}

/**
 * The one changeset the commit wrote, read as the superuser, past row level security.
 *
 * @return array<string, mixed>
 */
function committedChangeset(): array
{
    $rows = StorageTables::superuser()->table('changesets')->get(['actor_id', 'command', 'provenance_sources']);

    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    $changeset = [];

    foreach ($row instanceof stdClass ? get_object_vars($row) : [] as $column => $value) {
        $changeset[(string) $column] = $value;
    }

    return $changeset;
}

/**
 * Signs the member of staff in and lands on the start page.
 */
function signInToShell(string $email): PendingAwaitablePage
{
    $page = visit('/cms');

    $page->assertPathIs('/cms/login');
    $page->type('email', $email)
        ->type('password', ACTIONS_PASSWORD)
        ->click('button[type="submit"]');
    $page->assertPathIs('/cms');

    return $page;
}

beforeEach(function (): void {
    $tally = tallyWorld(new TallyWorld);
    $shell = new ShellWorld(app(), auditor: $tally->actor, fixtureBuild: false);
    $hasher = app(PasswordHasher::class);

    app(LocalCredentialStore::class)->bind($tally->actor, new LoginIdentifier(ACTIONS_EMAIL), $hasher->hash(new Password(ACTIONS_PASSWORD)));
    app(LocalCredentialStore::class)->bind($shell->viewer, new LoginIdentifier(ShellWorld::VIEWER_EMAIL), $hasher->hash(new Password(ACTIONS_PASSWORD)));
    app()->instance(CommandPipeline::class, $tally->pipeline());
});

afterEach(function (): void {
    TallyWorld::cleanUp();
});

it('runs an addon\'s action after a dry run the viewer reviews, shows the receipt, and records the contribution as the changeset\'s provenance', function (): void {
    $tally = tallyWorld();
    $page = signInToShell(ACTIONS_EMAIL);

    // The action is shown by the key of its label: the addon's catalogue comes with its bundle.
    PanelPage::assertPage($page, ['panel.home.body', 'panel.home.sign_out']);
    $page->assertSee('tally.add.label');

    $page->click('button:has-text("tally.add.label")');

    // The dry run committed nothing, and the viewer sees what the command would change.
    $page->assertSee('tally.add.label: what would change')
        ->assertSee('Dry run: nothing was saved')
        ->assertSee('Changes in the plan: 1')
        ->assertSee('tally:'.$tally->actor->toString());
    PanelPage::assertPage($page, ['panel.host.dry_run_confirm', 'panel.host.cancel']);

    expect(TallyTable::row(TallyId::fromString($tally->actor->toString())))->toBeNull();

    $page->click('button:has-text("'.PanelPage::text('panel.host.dry_run_confirm').'")');

    $page->assertSee('Saved')
        ->assertDontSee('tally.add.label: what would change');
    PanelPage::assertPage($page, ['panel.home.body']);

    $changeset = committedChangeset();
    $sources = $changeset['provenance_sources'] ?? null;

    expect(TallyTable::row(TallyId::fromString($tally->actor->toString())))->toBe([1, 1])
        ->and($changeset['command'] ?? null)->toBe('tally.add')
        ->and($changeset['actor_id'] ?? null)->toBe($tally->actor->toString())
        ->and(is_string($sources) ? json_decode($sources, true) : $sources)->toBe([ACTION_PROVENANCE]);
});

it('never shows the action to a viewer who may not run its command', function (): void {
    $page = signInToShell(ShellWorld::VIEWER_EMAIL);

    PanelPage::assertPage($page, ['panel.home.body', 'panel.home.sign_out']);
    $page->assertDontSee('tally.add.label');

    expect($page->script('document.querySelectorAll(\'[data-cms-point="shell.user-menu@1"]\').length'))->toBe(0);
});
