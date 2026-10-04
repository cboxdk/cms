---
title: Panel shell points
weight: 51
description: "The points of the panel's shell every page behind the login renders: the navigation, the addons' pages below /x/<namespace>/ with their data queries, and the actions of the viewer's menu with their prefill, confirmation and dry run."
---

# Panel shell points

<!-- extension-point: Cbox\Cms\Panel\Shell\Domain\Dto\ShellNavV1 -->
<!-- extension-point: Cbox\Cms\Panel\Shell\Domain\Dto\ShellPageV1 -->
<!-- extension-point: Cbox\Cms\Panel\Shell\Domain\Dto\ViewerSummaryV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/shell.nav.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/points/shell.page.v1.json -->
<!-- extension-point: packages/panel/resources/schemas/points/shell.user-menu.v1.json -->

The shell is what every page behind the login renders around its own content (PRD 13.4): the installation's brand, the viewer's menu, the sign-out and the navigation. Its three points are declared on the page `shell`, and every page behind the login renders them beside its own, so a contribution to them is active on every page, within its scope. All three are version 1 and `#[Experimental]`, so an addon lists them in `acceptsExperimental`. Their props schemas are `shell.nav.v1.json`, `shell.page.v1.json` and `shell.user-menu.v1.json` in `packages/panel/resources/schemas/points`, bound to the props classes as every [panel point](panel-points.md)'s; the first two describe an object without members.

| Point | Kind | Props | Contribution |
|---|---|---|---|
| `shell.nav@1` | nav | none (`ShellNavV1`) | a `NavContribution`: an entry of the navigation that opens a page of the addon, with its `label` and `icon` |
| `shell.page@1` | page | none (`ShellPageV1`) | a `PageContribution`: a page of the addon at `<prefix>/x/<namespace>/<path>`, whose component the bundle registers under the contribution's id, with a `data` query of the addon without required input |
| `shell.user-menu@1` | action | `ViewerSummaryV1`: `actor`, the viewer's actor id, and `issuer`, what the viewer's credential was issued for (`human`, `agent` or `service`) | an `ActionContribution`: a button of the viewer's menu that runs a command the addon may issue |

## Who gets what

The server decides, per request and viewer, which of the shell's contributions are active, as it does for every contribution ([panel contributions](panel-contributions.md#what-a-page-sends-a-viewer)), with three rules of the shell's own:

- An action is shown only to a viewer who may run its command: its command's permission is required beside what its scope requires, so the panel never offers a button the server would refuse.
- A page is shown only to a viewer who holds the permission its scope requires, and its nav entry only when the viewer gets the page, so no entry leads to a page the viewer may not open. An addon's page the viewer may open is among the pages a contribution may navigate to, by its id, with its address below `/x/<namespace>/`; `navigate('approvals.queue')` from the host goes there.
- A page's data query runs only on the page itself. Every page lists the addons' pages, so the shell's navigation and the command palette know them, without running their queries.

The nav entries are the pages of the command palette too: the panel's host gives the palette every nav entry the server left for the viewer, each opening its page, in render order.

## Pages

`GET <prefix>/x/{namespace}/{path}` serves the `PageContribution` of the addon with the namespace at the path, when the viewer gets it, as the Inertia page `Addon`: the shell, and the page's component with its only props, `data`, the result of its query run as the viewer through the query pipeline and sent as the deferred prop `ext.<namespace>` under the page's id, so the same data can be read over REST with the same credential, at the lower of the viewer's classification access and the addon's `reads`. A path no addon has, and the page of a viewer who may not open it, are the panel's page for a path it does not have, with 404.

## Actions

An action is data: the host renders a kit button per action, ordered, overflowing into a menu past the point's maximum, and a press runs the command through the contribution's own host, which holds the addon to its `issues`, with the document prefilled from the point's props by JSON pointer and the provenance `addon:<namespace>:<contribution>` as a source of the envelope's provenance, which the changeset records ([envelope JSON](envelope-json.md)). Before it runs, the action asks as its `confirm` says: `None` runs at once; `Confirm` asks the viewer in the kit's confirmation dialog; `DryRun` runs the command as a dry run first and shows the viewer what would change, the [dry run summary](dry-run-json.md), before they confirm the command for real; `Form` opens the command's form, which the page does. The receipt of the run, and the problem details of a rejection, are shown where the action is.

The example is in the `Unit` suite:

<!-- example: examples/Unit/Panel/ShellPointsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\ActionContribution;
use Cbox\Cms\Contracts\PanelPoints\Confirm;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\NavContribution;
use Cbox\Cms\Contracts\PanelPoints\PageContribution;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Scope;
use Cbox\Cms\Core\Access\Domain\Queries\ListGrants;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Panel\Boundary\Generated\Points\ShellNavCodecV1;
use Cbox\Cms\Panel\Boundary\Generated\Points\ViewerSummaryCodecV1;
use Cbox\Cms\Panel\Shell\Domain\Dto\ShellNavV1;
use Cbox\Cms\Panel\Shell\Domain\Dto\ViewerSummaryV1;

// An addon contributes to the panel's shell: a page of its own below /x/approvals/, reading its
// data with a query, shown to a viewer who may run approvals.list; the nav entry that opens it,
// listed only when the viewer gets the page; and an action of the viewer's menu, which runs a
// command the addon may issue, prefilled with the viewer's actor id from the point's props, after
// a dry run the viewer reviews. cms:build holds the data query to one of the addon's own without
// required input, and the action's command to the addon's issues; the kernel's are named here only
// to show the shape.

it('contributes a page, its nav entry and an action of the viewer s menu', function (): void {
    $page = new PageContribution(new ContributionId('approvals.queue'), 'shell.page@1', 'queue', ListGrants::class, scope: new Scope(requires: new CommandName('approvals.list')));
    $entry = new NavContribution(new ContributionId('approvals.queue-link'), 'shell.nav@1', 'approvals.nav.queue', 'approvals.queue', 'inbox');
    $action = new ActionContribution(new ContributionId('approvals.unpublish-mine'), 'shell.user-menu@1', UnpublishEntry::class, 'approvals.unpublish.label', prefill: ['entry' => '/actor'], confirm: Confirm::DryRun);

    expect($page->kind())->toBe(PointKind::Page)
        ->and($page->runsCode())->toBeTrue()
        ->and($entry->page)->toBe('approvals.queue')
        ->and($entry->runsCode())->toBeFalse()
        ->and($action->prefill)->toBe(['entry' => '/actor'])
        ->and($action->confirm)->toBe(Confirm::DryRun);
});

it('hands the viewer s menu the viewer as its props, and the navigation and the pages no props', function (): void {
    $viewer = new ViewerSummaryV1(ActorId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'), IssuerKind::Human);

    expect(new ViewerSummaryCodecV1()->encode($viewer, ClassificationAccess::Public))->toBe('{"actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","issuer":"human"}')
        ->and(new ShellNavCodecV1()->encode(new ShellNavV1, ClassificationAccess::Public))->toBe('{}');
});
```
