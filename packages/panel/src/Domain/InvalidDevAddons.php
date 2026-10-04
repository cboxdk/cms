<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use RuntimeException;

/**
 * CBOX_CMS_PANEL_DEV_ADDONS is not a list of `<namespace>=<origin>` pairs of the form DevServer
 * takes (PRD 13.4). The message says what is wrong and never repeats a value that is not of the
 * form, only the pair's position.
 */
#[Internal]
final class InvalidDevAddons extends RuntimeException
{
    public const string CODE = 'panel_dev_addons_invalid';

    public static function because(string $variable, string $reason): self
    {
        return new self("{$variable} cannot be used: {$reason} Set it to one or more pairs of an addon namespace and a loopback origin over http, joined by commas, such as approvals=http://localhost:5174, or unset it.");
    }
}
