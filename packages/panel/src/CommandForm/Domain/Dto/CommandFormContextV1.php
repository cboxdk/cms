<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Panel\CommandForm\Domain\CommandForm;

/**
 * The props of command.form.aside@1, the aside of the generic command form (PRD 13.4, section 3.3
 * of the panel extension architecture): a slot in the form page's aside region, beside the form,
 * where an addon adds help and context about the command being run, such as what the command
 * does in the addon's own terms or a link to its documentation. Its props are what the form is
 * about: the command's name, its contract version and the title of its JSON Schema. A
 * contribution whose scope names commands is active on the form of those commands alone.
 */
#[Experimental]
#[PanelPoint(name: CommandForm::ASIDE, version: 1, kind: PointKind::Slot, page: CommandForm::PAGE, since: '1.0', label: 'panel.points.command_form_aside', region: Region::Aside)]
final readonly class CommandFormContextV1
{
    public function __construct(
        public CommandName $command,
        public int $version,
        public string $title,
    ) {}
}
