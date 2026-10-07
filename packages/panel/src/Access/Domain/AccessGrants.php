<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointName;

/**
 * The grants page (PRD 5.10, 13.4): the panel's page `access.grants`, which lists the grants that
 * have not ended on the nodes the person reaches, read with the query grant.list through the
 * query pipeline as the person, a page at a time, and from which a person who holds grant.assign
 * and grant.revoke assigns a grant, with pickers for the actor, the role and the node, and revokes
 * one through the Inertia profile. The pickers read actor.list, role.list and node.list, the
 * PICKERS queries, as the optional prop PICKERS, which the page asks for when its form opens. It
 * declares the slot its sections are contributed to, `access.grants.sections@1`
 * (AccessGrantsSectionsV1 in Dto), and the core's nav entry to it is in CoreContributions.
 */
#[Internal]
final readonly class AccessGrants
{
    /** The page's name, which its point names as its page and its nav entry opens. */
    public const string PAGE = 'access.grants';

    /** The point of the page's sections, `access.grants.sections@1`. */
    public const string SECTIONS = 'access.grants.sections';

    /** The query the page's props come from. */
    public const string QUERY = 'grant.list';

    public const int QUERY_VERSION = 1;

    /** The queries the pickers of the page's form read, each at version 1. */
    public const string ACTORS = 'actor.list';

    public const string ROLES = 'role.list';

    public const string NODES = 'node.list';

    /** The page's path below the panel's prefix. */
    public const string PATH = 'access/grants';

    /** The optional prop of the pickers' reads, which the page asks for when its form opens. */
    public const string PICKERS = 'pickers';

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
     * The query the page reads, grant.list version 1.
     */
    public static function query(): CommandRef
    {
        return new CommandRef(new CommandName(self::QUERY), self::QUERY_VERSION);
    }

    /**
     * The queries the pickers read, each at version 1: actor.list, role.list and node.list.
     *
     * @return list<CommandRef>
     */
    public static function pickers(): array
    {
        return [
            new CommandRef(new CommandName(self::ACTORS), 1),
            new CommandRef(new CommandName(self::ROLES), 1),
            new CommandRef(new CommandName(self::NODES), 1),
        ];
    }
}
