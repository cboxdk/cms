<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Login\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointName;

/**
 * The panel's login page as a page of panel points (PRD 13.4, section 3.11 of the panel extension
 * architecture): a credential page, which runs no addon code, so its one point takes data alone,
 * declared in Dto: the notices above the login form (LoginNoticeV1).
 */
#[Internal]
final readonly class Login
{
    /** The page name the login page's points are declared on. */
    public const string PAGE = 'login';

    /** The point of the notices above the login form, `login.notice@1`. */
    public const string NOTICE = 'login.notice';

    private function __construct() {}

    public static function page(): PageName
    {
        return new PageName(self::PAGE);
    }

    public static function notice(): PointName
    {
        return new PointName(self::NOTICE);
    }

    /**
     * The id of the notices point, version 1.
     */
    public static function notices(): PointId
    {
        return new PointId(self::notice(), 1);
    }
}
