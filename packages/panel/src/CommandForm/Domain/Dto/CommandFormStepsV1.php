<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Panel\CommandForm\Domain\CommandForm;

/**
 * The props of command.form.steps@1, the flow steps of the generic command form (PRD 13.4,
 * section 3.8 of the panel extension architecture): a flow step point without props. A FlowStep
 * to it runs as a numbered step of the form of the command it names, before the submit or after
 * the receipt, with the draft document, a dry run of it, patch() for the paths its manifest
 * declares, issue() for the commands its addon may issue, next() and cancel(). The core's own
 * confirmation runs last before the submit, and no step can skip it; the first cancel stops the
 * flow in its addon's name.
 */
#[Experimental]
#[PanelPoint(name: CommandForm::STEPS, version: 1, kind: PointKind::FlowStep, page: CommandForm::PAGE, since: '1.0', label: 'panel.points.command_form_steps')]
final readonly class CommandFormStepsV1 {}
