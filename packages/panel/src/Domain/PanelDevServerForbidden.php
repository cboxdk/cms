<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * A process that serves the panel runs with CBOX_CMS_PANEL_DEV_ADDONS outside the local
 * environment (PRD 13.4): the variable widens the panel's content security policy to a dev server,
 * which only development may do, so the panel's provider refuses to boot.
 */
#[Internal]
final class PanelDevServerForbidden extends LogicException
{
    public const string CODE = 'panel_dev_server_forbidden';

    public static function outsideLocal(string $variable, string $environment): self
    {
        return new self("{$variable} is set in the environment \"{$environment}\", and a dev server for an addon's panel UI is for the local environment alone: it widens the panel's content security policy to the server's origin. Unset it in this environment, or build the addon's bundle and name it in its manifest.");
    }
}
