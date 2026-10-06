<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Browser\Panel;

use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\Contributions\ShellWorld;
use Cbox\Cms\Testkit\Panel\PanelVisit;
use Cbox\Cms\Tests\Support\Browser\PanelPage;
use LogicException;

/*
 * The testkit's visitPanelAs for an addon's Pest Browser tests (PRD 13.4, section 7 of the panel
 * extension architecture), in Chromium against the build `composer panel:build` writes, this
 * checkout's test database and Valkey: PanelVisit::as() gives the actor a local account of the
 * test's own, signs in through the panel's login form as a person does and lands on the path
 * below the panel's prefix; assertFill(), assertNoFill() and assertFillOrder() read the
 * attributes the panel's host puts on every contribution it renders. The test addon of
 * ContributionWorld contributes the page tally.board under /x/tally/board, which the auditor
 * reaches and the viewer, who lacks its permission, does not; its bundle is not served here, so
 * the page's contribution is attributed as not loaded and no fill is rendered. Its action ADD on
 * the shell's user menu runs no code, so the shell renders it for the auditor, who holds its
 * command, and the fill assertions find it; the viewer is shown none.
 */

/**
 * The world of the shell, which beforeEach makes for each test.
 */
function visitedShell(?ShellWorld $world = null): ShellWorld
{
    /** @var ShellWorld|null $current */
    static $current = null;

    if ($world instanceof ShellWorld) {
        $current = $world;
    }

    return $current instanceof ShellWorld ? $current : throw new LogicException('No shell world.');
}

beforeEach(function (): void {
    visitedShell(new ShellWorld(app(), fixtureBuild: false));
});

it('signs the actor in and lands on the path below the prefix, where the fill assertions read what the host renders', function (): void {
    $shell = visitedShell();

    $visit = PanelVisit::as($shell->auditor, '/x/tally/board');

    // The addon's bundle is not served in this world, so the page's one contribution is attributed
    // as not loaded and the point renders no fill; the assertions read the host's attributes alone.
    $visit->page->assertPathIs('/cms/x/tally/board')
        ->assertNoJavaScriptErrors()
        ->assertSee(PanelPage::text('panel.host.unavailable_title', ['addon' => 'tally']));
    $visit->assertNoFill(ContributionWorld::BOARD)
        ->assertFillOrder('shell.page@1', []);
});

it('lands on the start page when no path is given, where the fill assertions find the action the shell renders', function (): void {
    $shell = visitedShell();

    $visit = PanelVisit::as($shell->auditor);

    $visit->page->assertPathIs('/cms');
    PanelPage::assertPage($visit->page, ['panel.home.body']);

    // The auditor holds the command of the addon's action ADD, which runs no code and so needs
    // no bundle: the shell's user menu renders it, and the assertions find it by the host's
    // attributes, on its point alone.
    $visit->assertFill(ContributionWorld::ADD)
        ->assertFillOrder('shell.user-menu@1', [ContributionWorld::ADD])
        ->assertNoFill(ContributionWorld::BOARD);
});

it('shows a viewer without the permission no fill of the addon page', function (): void {
    $shell = visitedShell();

    $visit = PanelVisit::as($shell->viewer, '/x/tally/board');

    $visit->page->assertSee(PanelPage::text('panel.not_found.title'));
    $visit->assertNoFill(ContributionWorld::BOARD)
        ->assertFillOrder('shell.page@1', []);
});

it('shows a viewer who may not run the action no fill of it on the start page', function (): void {
    $shell = visitedShell();

    $visit = PanelVisit::as($shell->viewer);

    PanelPage::assertPage($visit->page, ['panel.home.body']);
    $visit->assertNoFill(ContributionWorld::ADD)
        ->assertFillOrder('shell.user-menu@1', []);
});
