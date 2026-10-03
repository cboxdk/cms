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
    /** The path below the prefix of the Inertia profile, `<prefix>/commands/{command}/v{version}` (Command). */
    public const string COMMANDS_PATH = 'commands';

    /** GET <prefix>/login: the login page. */
    case Login = 'cbox-cms.panel.login';

    /** POST <prefix>/login: a local login from the login form. */
    case LoginSubmit = 'cbox-cms.panel.login.submit';

    /** GET <prefix>/forgot-password: the page that asks for a password reset link. */
    case ForgotPassword = 'cbox-cms.panel.forgot-password';

    /** POST <prefix>/forgot-password: a request for a password reset link from that page. */
    case ForgotPasswordSubmit = 'cbox-cms.panel.forgot-password.submit';

    /** GET <prefix>/reset-password/{token}: the page a reset link opens, which sets a new password. */
    case ResetPassword = 'cbox-cms.panel.reset-password';

    /** POST <prefix>/reset-password: a new password with the token of a reset link. */
    case ResetPasswordSubmit = 'cbox-cms.panel.reset-password.submit';

    /** POST <prefix>/logout: ends the person's session. */
    case Logout = 'cbox-cms.panel.logout';

    /** GET <prefix>/theme/{version}.css: the stylesheet of the theme cms:build composed. */
    case Theme = 'cbox-cms.panel.theme';

    /** GET <prefix>/brand/{name}: a file of the installation's brand, a logo or the favicon. */
    case Brand = 'cbox-cms.panel.brand';

    /** GET <prefix>: the panel's start page. */
    case Home = 'cbox-cms.panel.home';

    /** POST <prefix>/commands/{command}/v{version}: a command through the Inertia profile, as the person. */
    case Command = 'cbox-cms.panel.command';
}
