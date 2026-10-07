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
 * It declares the slot its aside is contributed to, `command.form.aside@1` (CommandFormContextV1
 * in Dto), where an addon adds help and context about the command; the panel extension
 * architecture adds the form's checks, steps and decorators as points of their own.
 */
#[Internal]
final readonly class CommandForm
{
    /** The page's name, which its points name as their page and a contribution's scope may name. */
    public const string PAGE = 'command.form';

    /** The point of the page's aside, `command.form.aside@1`. */
    public const string ASIDE = 'command.form.aside';

    private function __construct() {}

    public static function page(): PageName
    {
        return new PageName(self::PAGE);
    }

    public static function aside(): PointName
    {
        return new PointName(self::ASIDE);
    }
}
