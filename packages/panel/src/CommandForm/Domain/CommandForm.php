<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\CommandForm\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointName;

/**
 * The generic command form (PRD 6.1, 13.4, GUARDRAILS 8): the panel's page `command.form`, at
 * `GET <prefix>/commands/<name>/v<version>`, the address the Inertia profile runs the command at,
 * which renders a form from the command's JSON Schema for any command the profile exposes, so
 * every command the palette offers can be run with the keyboard. The page names no command: its
 * props carry the command's name, version and schema, and the form is read from the schema alone.
 * It declares the points of the form (section 8 of the panel extension architecture), each with
 * its props class in Dto: the slot of its aside, `command.form.aside@1` (CommandFormContextV1),
 * where an addon adds help and context about the command; the form checks,
 * `command.form.checks@1` (CommandFormChecksV1); the flow steps, `command.form.steps@1`
 * (CommandFormStepsV1); the decorator of the submit, `command.form.submit@1`
 * (CommandFormSubmitV1); the decorator of the receipt, `command.form.receipt@1`
 * (CommandFormReceiptV1); the replacement of a field's input, `command.form.field@1`
 * (FieldInputPropsV1), keyed by the value class the command binds the member to; and the slot
 * below what a dry run would change, `command.form.dryrun@1` (DryRunViewV1). The props of the
 * receipt, the field and the dry run exist only in the browser, so the page holds them
 * (RenderedPoint::heldByPage()).
 */
#[Internal]
final readonly class CommandForm
{
    /** The page's name, which its points name as their page and a contribution's scope may name. */
    public const string PAGE = 'command.form';

    /** The point of the page's aside, `command.form.aside@1`. */
    public const string ASIDE = 'command.form.aside';

    /** The point of the form's checks, `command.form.checks@1`. */
    public const string CHECKS = 'command.form.checks';

    /** The point of the form's flow steps, `command.form.steps@1`. */
    public const string STEPS = 'command.form.steps';

    /** The point that decorates the form's submit, `command.form.submit@1`. */
    public const string SUBMIT = 'command.form.submit';

    /** The point that decorates the receipt, `command.form.receipt@1`. */
    public const string RECEIPT = 'command.form.receipt';

    /** The point that replaces a field's input, `command.form.field@1`. */
    public const string FIELD = 'command.form.field';

    /** The point of the sections below what a dry run would change, `command.form.dryrun@1`. */
    public const string DRY_RUN = 'command.form.dryrun';

    private function __construct() {}

    public static function page(): PageName
    {
        return new PageName(self::PAGE);
    }

    public static function aside(): PointName
    {
        return new PointName(self::ASIDE);
    }

    public static function checks(): PointName
    {
        return new PointName(self::CHECKS);
    }

    public static function steps(): PointName
    {
        return new PointName(self::STEPS);
    }

    public static function submit(): PointName
    {
        return new PointName(self::SUBMIT);
    }

    public static function receipt(): PointName
    {
        return new PointName(self::RECEIPT);
    }

    public static function field(): PointName
    {
        return new PointName(self::FIELD);
    }

    public static function dryRun(): PointName
    {
        return new PointName(self::DRY_RUN);
    }
}
