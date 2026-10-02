<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The names of the panel's routes that its pages and redirects lead to (PRD 13.4), which
 * PanelRoutes gives them.
 */
#[Internal]
enum PanelRoute: string
{
    /** GET <prefix>/login: the login page. */
    case Login = 'cbox-cms.panel.login';

    /** POST <prefix>/login: a local login from the login form. */
    case LoginSubmit = 'cbox-cms.panel.login.submit';

    /** POST <prefix>/logout: ends the person's session. */
    case Logout = 'cbox-cms.panel.logout';

    /** GET <prefix>: the panel's start page. */
    case Home = 'cbox-cms.panel.home';

    /** POST <prefix>/commands/{command}/v{version}: a command through the Inertia profile, as the person. */
    case Command = 'cbox-cms.panel.command';
}
