<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointName;

/**
 * The roles page (PRD 5.10, 13.4): the panel's page `access.roles`, which lists the roles of the
 * installation with their classification ceilings and permissions, read with the query role.list
 * through the query pipeline as the person, a page at a time, and from which a person who holds
 * role.create and role.set_permissions creates a role and replaces a role's permissions through
 * the Inertia profile. It declares the slot its sections are contributed to,
 * `access.roles.sections@1` (AccessRolesSectionsV1 in Dto), and the core's nav entry to it is in
 * CoreContributions.
 */
#[Internal]
final readonly class AccessRoles
{
    /** The page's name, which its point names as its page and its nav entry opens. */
    public const string PAGE = 'access.roles';

    /** The point of the page's sections, `access.roles.sections@1`. */
    public const string SECTIONS = 'access.roles.sections';

    /** The query the page's props come from. */
    public const string QUERY = 'role.list';

    public const int QUERY_VERSION = 1;

    /** The page's path below the panel's prefix. */
    public const string PATH = 'access/roles';

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
     * The query the page reads, role.list version 1.
     */
    public static function query(): CommandRef
    {
        return new CommandRef(new CommandName(self::QUERY), self::QUERY_VERSION);
    }
}
