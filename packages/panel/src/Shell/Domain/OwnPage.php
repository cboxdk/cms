<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Shell\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Panel\Access\Domain\AccessGrants;
use Cbox\Cms\Panel\Access\Domain\AccessRoles;
use Cbox\Cms\Panel\Account\Domain\AccountMe;
use Cbox\Cms\Panel\Domain\PanelRoute;

/**
 * The panel's own pages behind the login (PRD 13.4): each by the page id a panel point names as
 * its page and a nav entry opens, with the route that serves it and, for a page that reads, the
 * query its props come from. The pages a contribution may navigate to are these and every addon's
 * page the viewer may open; a nav entry the core or a module contributes to shell.nav@1 names one
 * of these as its page. A page that reads gets its props from the query pipeline with the session
 * credential and the query's result codec, so the same read is on REST too: QueryPageParity holds
 * every query a page reads, the pickers of its forms included, to a REST route.
 */
#[Internal]
enum OwnPage: string
{
    /** The start page, `<prefix>`. */
    case Home = 'home';

    /** The who-am-I page, `<prefix>/account/me`, which reads actor.me. */
    case AccountMe = AccountMe::PAGE;

    /** The roles page, `<prefix>/access/roles`, which reads role.list (PRD 5.10). */
    case AccessRoles = AccessRoles::PAGE;

    /** The grants page, `<prefix>/access/grants`, which reads grant.list and, for its pickers, actor.list, role.list and node.list (PRD 5.10). */
    case AccessGrants = AccessGrants::PAGE;

    public function name(): PageName
    {
        return new PageName($this->value);
    }

    public function route(): PanelRoute
    {
        return match ($this) {
            self::Home => PanelRoute::Home,
            self::AccountMe => PanelRoute::AccountMe,
            self::AccessRoles => PanelRoute::AccessRoles,
            self::AccessGrants => PanelRoute::AccessGrants,
        };
    }

    /**
     * The query the page's props come from, or null for a page that reads nothing.
     */
    public function query(): ?CommandRef
    {
        return match ($this) {
            self::Home => null,
            self::AccountMe => AccountMe::query(),
            self::AccessRoles => AccessRoles::query(),
            self::AccessGrants => AccessGrants::query(),
        };
    }

    /**
     * Every query the page reads as the person: the one its props come from, and those of the
     * pickers of its forms, each of which is on REST too (QueryPageParity).
     *
     * @return list<CommandRef>
     */
    public function queries(): array
    {
        $query = $this->query();

        return [
            ...($query instanceof CommandRef ? [$query] : []),
            ...match ($this) {
                self::AccessGrants => AccessGrants::pickers(),
                default => [],
            },
        ];
    }

    /**
     * The page with the id, or null when the panel has no own page of that id.
     */
    public static function named(string $page): ?self
    {
        return self::tryFrom($page);
    }
}
