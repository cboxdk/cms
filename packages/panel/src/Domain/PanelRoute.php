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

    /** GET <prefix>/addons/{addon}/{hash}/{path}: a file of an addon's panel bundle, by the bundle's hash. */
    case AddonAsset = 'cbox-cms.panel.addon-asset';

    /** POST <prefix>/csp-report: where a browser reports a violation of the panel's Content-Security-Policy. */
    case CspReport = 'cbox-cms.panel.csp-report';

    /** GET <prefix>: the panel's start page. */
    case Home = 'cbox-cms.panel.home';

    /** POST <prefix>/commands/{command}/v{version}: a command through the Inertia profile, as the person. */
    case Command = 'cbox-cms.panel.command';

    /**
     * Whether a page of the route loads the addons' panel UI (PRD 13.4, decision D13 of the
     * panel extension architecture): a credential page, where a person types a password or
     * handles a reset link, never does, so no addon's code runs near it, and neither does the
     * page for an address the panel does not have. The routes that are no page, the files and the
     * report, do not either.
     */
    public function allowsAddons(): bool
    {
        return match ($this) {
            self::Home, self::Command => true,
            self::Login, self::LoginSubmit, self::ForgotPassword, self::ForgotPasswordSubmit, self::ResetPassword, self::ResetPasswordSubmit, self::Logout, self::Theme, self::Brand, self::AddonAsset, self::CspReport => false,
        };
    }
}
