<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Codecs\JsonDocument;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Panel\CommandForm\Domain\CommandForm;

/**
 * The props of command.form.receipt@1, the decorator of the receipt a run of the generic command
 * form shows (PRD 13.4, section 3.5 of the panel extension architecture): the command's name and
 * contract version, the receipt the command answered with, as the kernel's receipt codec writes it
 * (receipt.v1.json), and the problem details of a rejection (problem.v1.json), or null. A
 * DecoratorContribution to it adds content before or after the receipt and a badge, and tightens
 * nothing: the point declares no tightening, so a receipt is never hidden or disabled. The props
 * exist only in the browser, once the command answered, so the page holds them.
 */
#[Experimental]
#[PanelPoint(name: CommandForm::RECEIPT, version: 1, kind: PointKind::Decorator, page: CommandForm::PAGE, since: '1.0', label: 'panel.points.command_form_receipt')]
final readonly class CommandFormReceiptV1
{
    public function __construct(
        public CommandName $command,
        public int $version,
        public JsonDocument $receipt,
        public ?JsonDocument $problem,
    ) {}
}
