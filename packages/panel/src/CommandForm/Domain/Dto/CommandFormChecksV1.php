<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Panel\CommandForm\Domain\CommandForm;

/**
 * The props of command.form.checks@1, the checks of the generic command form (PRD 13.4, section
 * 3.7 of the panel extension architecture): a form check point without props. A FormCheck to it
 * is a pure function of the form's command document to issues, run on every edit of the form of
 * the command it names, within 16 ms; its issues are shown at their fields, an issue of severity
 * acknowledge holds the submit until the viewer ticks it, and an issue of severity error blocks the
 * submit only for a check that mirrors a ValidateHook or AuthorizeHook of its addon on the same
 * command, so the rule holds over REST, MCP and the CLI too. After a submit, the kernel's errors
 * at their paths replace the checks' issues there.
 */
#[Experimental]
#[PanelPoint(name: CommandForm::CHECKS, version: 1, kind: PointKind::FormCheck, page: CommandForm::PAGE, since: '1.0', label: 'panel.points.command_form_checks')]
final readonly class CommandFormChecksV1 {}
