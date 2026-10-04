<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Shell\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointName;

/**
 * The shell of the panel (PRD 13.4, section 8 of the panel extension architecture): what every
 * page behind the login renders around its own content, and so the page every such page renders
 * the points of. Its points are declared in Dto: the navigation entries (ShellNavV1), the addons'
 * pages (ShellPageV1) and the actions of the viewer's menu (ViewerSummaryV1). A panel page renders
 * the shell's points beside its own, so a contribution to them is active on every page, within its
 * scope.
 */
#[Internal]
final readonly class Shell
{
    /** The page name the shell's points are declared on. */
    public const string PAGE = 'shell';

    /** The point of the navigation entries, `shell.nav@1`. */
    public const string NAV = 'shell.nav';

    /** The point of the addons' pages, `shell.page@1`. */
    public const string PAGES = 'shell.page';

    /** The point of the actions of the viewer's menu, `shell.user-menu@1`. */
    public const string USER_MENU = 'shell.user-menu';

    private function __construct() {}

    public static function page(): PageName
    {
        return new PageName(self::PAGE);
    }

    public static function nav(): PointName
    {
        return new PointName(self::NAV);
    }

    public static function pages(): PointName
    {
        return new PointName(self::PAGES);
    }

    public static function userMenu(): PointName
    {
        return new PointName(self::USER_MENU);
    }
}
