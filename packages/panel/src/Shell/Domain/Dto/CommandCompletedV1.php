<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Shell\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Panel\Shell\Domain\Shell;

/**
 * The event of panel.observe.command@1, the observers of a command that completed (PRD 13.4,
 * section 3.9 of the panel extension architecture): the command's name and contract version, how
 * it ended, as the receipt says it (rejected, committed, committed_wait_timeout or dry_run), and
 * the changeset it committed, or null when it committed nothing. An ObserverContribution to it is
 * a function the host calls with the event after the command answered, wherever a page of the
 * panel ran a command as the viewer: the generic command form, and the pages that run their own
 * commands through the host. It is read-only: it cannot affect the flow, and one that throws is
 * caught and named after its addon. The event exists only in the browser, once the command
 * answered, so the page holds it, as it holds the props of the form's receipt decorator.
 */
#[Experimental]
#[PanelPoint(name: Shell::OBSERVE, version: 1, kind: PointKind::Observer, page: Shell::PAGE, since: '1.0', label: 'panel.points.panel_observe_command')]
final readonly class CommandCompletedV1
{
    public function __construct(
        public CommandName $command,
        public int $version,
        public Outcome $outcome,
        public ?ChangesetId $changeset,
    ) {}
}
