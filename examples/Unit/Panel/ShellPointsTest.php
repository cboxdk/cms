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
