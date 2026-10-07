<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Panel\CommandForm\Domain\CommandForm;

/**
 * The props of command.form.dryrun@1, the sections below what a dry run of the generic command
 * form would change (PRD 13.4, 8.4): the command's name and contract version, the summary of the
 * dry run as the kernel's codec writes it (dry-run-summary.v1.json: the plan's summary, the blast
 * radius and what becomes visible), and the dry run's receipt (receipt.v1.json). A SlotFill to it
 * renders its own markup below the report, such as what the change means in the addon's own
 * terms. The props exist only in the browser, once the dry run answered, so the page holds them.
 */
#[Experimental]
#[PanelPoint(name: CommandForm::DRY_RUN, version: 1, kind: PointKind::Slot, page: CommandForm::PAGE, since: '1.0', label: 'panel.points.command_form_dryrun', region: Region::Sections)]
final readonly class DryRunViewV1
{
    public function __construct(
        public CommandName $command,
        public int $version,
        public JsonDocument $summary,
        public JsonDocument $receipt,
    ) {}
}
