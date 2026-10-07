<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Tighten;
use Cbox\Cms\Panel\CommandForm\Domain\CommandForm;

/**
 * The props of command.form.submit@1, the decorator of the generic command form's submit (PRD
 * 13.4, section 3.5 of the panel extension architecture): what the form is about, the command's
 * name, its contract version and the title of its JSON Schema, as the aside gets them. A
 * DecoratorContribution to it adds content before or after the form's actions and a badge, and
 * tightens them: a disabled reason, which blocks the submit and so follows the mirror rule, a
 * description appended below the actions, and a tone that moves the submit towards danger. The
 * decorators' tightenings combine most restrictively, and the default always renders once.
 */
#[Experimental]
#[PanelPoint(name: CommandForm::SUBMIT, version: 1, kind: PointKind::Decorator, page: CommandForm::PAGE, since: '1.0', label: 'panel.points.command_form_submit', tightens: [Tighten::DisabledReason, Tighten::Description, Tighten::ToneTowardsDanger])]
final readonly class CommandFormSubmitV1
{
    public function __construct(
        public CommandName $command,
        public int $version,
        public string $title,
    ) {}
}
