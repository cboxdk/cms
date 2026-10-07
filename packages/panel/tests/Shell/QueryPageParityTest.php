<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Shell;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;
use Cbox\Cms\Core\Access\Actions\ListGrantsAction;
use Cbox\Cms\Core\Access\Actions\ListRolesAction;
use Cbox\Cms\Core\Access\Domain\Queries\ListGrants;
use Cbox\Cms\Core\Access\Domain\Queries\ListRoles;
use Cbox\Cms\Core\Identity\Actions\ListActorsAction;
use Cbox\Cms\Core\Identity\Actions\WhoAmIAction;
use Cbox\Cms\Core\Identity\Domain\Queries\ListActors;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Structure\Actions\ListNodesAction;
use Cbox\Cms\Core\Structure\Domain\Queries\ListNodes;
use Cbox\Cms\Panel\Account\Domain\AccountMe;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Shell\Domain\QueryPageParity;

/*
 * The panel's own pages that read stay in parity with REST (decided by Sylvester on 29 September
 * 2026): the who-am-I page reads actor.me, the roles page role.list and the grants page grant.list
 * and, for its pickers, actor.list, role.list and node.list, so the registry must expose each on
 * REST, or the page is an Inertia query page without a REST route, which SurfaceParityTest fails
 * on. A planted registry whose actor.me is on Inertia alone, or which lacks the queries, names
 * every page and query it fails; one with every query on REST and Inertia is not named, and the
 * start page reads nothing.
 */

/**
 * @param  list<Surface>  $surfaces
 */
function actorMeEntry(array $surfaces): ActionEntry
{
    return new ActionEntry(WhoAmIAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName(AccountMe::QUERY), AccountMe::QUERY_VERSION, WhoAmI::class, $surfaces);
}

/**
 * The query actions of the access pages, each on REST and Inertia.
 *
 * @return list<ActionEntry>
 */
function accessEntries(): array
{
    $panel = [Surface::Rest, Surface::Inertia];

    return [
        new ActionEntry(ListRolesAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName('role.list'), 1, ListRoles::class, $panel),
        new ActionEntry(ListGrantsAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName('grant.list'), 1, ListGrants::class, $panel),
        new ActionEntry(ListActorsAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName('actor.list'), 1, ListActors::class, $panel),
        new ActionEntry(ListNodesAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName('node.list'), 1, ListNodes::class, $panel),
    ];
}

/** Every query of every own page, as broken() names them for a registry without any. */
const EVERY_PAGE_QUERY = ['account.me reads actor.me@1', 'access.roles reads role.list@1', 'access.grants reads grant.list@1', 'access.grants reads actor.list@1', 'access.grants reads role.list@1', 'access.grants reads node.list@1'];

it('names the who-am-I page when its query is planted on Inertia alone, and every page and query of a registry without them', function (): void {
    expect(QueryPageParity::broken(new CompiledRegistry([], [], [actorMeEntry([Surface::Inertia]), ...accessEntries()]), OwnPage::cases()))->toBe(['account.me reads actor.me@1'])
        ->and(QueryPageParity::broken(CompiledRegistry::empty(), OwnPage::cases()))->toBe(EVERY_PAGE_QUERY);
});

it('names the grants page for each picker query the registry lacks', function (): void {
    $withoutNodes = array_values(array_filter(accessEntries(), static fn (ActionEntry $entry): bool => $entry->command->value !== 'node.list'));

    expect(QueryPageParity::broken(new CompiledRegistry([], [], [actorMeEntry([Surface::Rest, Surface::Inertia]), ...$withoutNodes]), OwnPage::cases()))->toBe(['access.grants reads node.list@1']);
});

it('accepts a registry with every query on REST, and the start page reads nothing', function (): void {
    expect(QueryPageParity::broken(new CompiledRegistry([], [], [actorMeEntry([Surface::Rest, Surface::Inertia]), ...accessEntries()]), OwnPage::cases()))->toBe([])
        ->and(OwnPage::Home->query())->toBeNull()
        ->and(OwnPage::Home->queries())->toBe([])
        ->and(OwnPage::AccountMe->query()?->toString())->toBe('actor.me@1')
        ->and(OwnPage::AccessRoles->query()?->toString())->toBe('role.list@1')
        ->and(OwnPage::AccessGrants->query()?->toString())->toBe('grant.list@1')
        ->and(array_map(static fn (CommandRef $query): string => $query->toString(), OwnPage::AccessGrants->queries()))->toBe(['grant.list@1', 'actor.list@1', 'role.list@1', 'node.list@1'])
        ->and(OwnPage::named('account.me'))->toBe(OwnPage::AccountMe)
        ->and(OwnPage::named('access.roles'))->toBe(OwnPage::AccessRoles)
        ->and(OwnPage::named('access.grants'))->toBe(OwnPage::AccessGrants)
        ->and(OwnPage::named('tally.board'))->toBeNull();
});
