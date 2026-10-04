<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Account\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointName;

/**
 * The who-am-I page (PRD 5.16, 13.4): the panel's page `account.me`, which shows a person their
 * own actor, profile and grants, read with the query actor.me through the query pipeline as the
 * person. It declares the slot its sections are contributed to, `account.me.sections@1`
 * (AccountMeSectionsV1 in Dto), and the core's nav entry to it is in CoreContributions.
 */
#[Internal]
final readonly class AccountMe
{
    /** The page's name, which its point names as its page and its nav entry opens. */
    public const string PAGE = 'account.me';

    /** The point of the page's sections, `account.me.sections@1`. */
    public const string SECTIONS = 'account.me.sections';

    /** The query the page's props come from. */
    public const string QUERY = 'actor.me';

    public const int QUERY_VERSION = 1;

    /** The page's path below the panel's prefix. */
    public const string PATH = 'account/me';

    private function __construct() {}

    public static function page(): PageName
    {
        return new PageName(self::PAGE);
    }

    public static function sections(): PointName
    {
        return new PointName(self::SECTIONS);
    }

    /**
     * The query the page reads, actor.me version 1.
     */
    public static function query(): CommandRef
    {
        return new CommandRef(new CommandName(self::QUERY), self::QUERY_VERSION);
    }
}
