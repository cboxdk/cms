<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Shell;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Identity\Actions\WhoAmIAction;
use Cbox\Cms\Core\Identity\Domain\Queries\WhoAmI;
use Cbox\Cms\Core\Registry\Domain\ActionKind;
use Cbox\Cms\Core\Registry\Domain\Dto\ActionEntry;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Panel\Account\Domain\AccountMe;
use Cbox\Cms\Panel\Shell\Domain\OwnPage;
use Cbox\Cms\Panel\Shell\Domain\QueryPageParity;

/*
 * The panel's own pages that read stay in parity with REST (decided by Sylvester on 29 September
 * 2026): the who-am-I page reads actor.me, so the registry must expose actor.me on REST, or the
 * page is an Inertia query page without a REST route, which SurfaceParityTest fails on. A planted
 * registry whose actor.me is on Inertia alone, or whose actor.me is missing, is named; one with
 * actor.me on REST and Inertia is not, and the start page reads nothing.
 */

/**
 * @param  list<Surface>  $surfaces
 */
function actorMeEntry(array $surfaces): ActionEntry
{
    return new ActionEntry(WhoAmIAction::class, 'cboxdk/cms', ActionKind::Query, new CommandName(AccountMe::QUERY), AccountMe::QUERY_VERSION, WhoAmI::class, $surfaces);
}

it('names the who-am-I page when its query is planted on Inertia alone or is missing', function (): void {
    expect(QueryPageParity::broken(new CompiledRegistry([], [], [actorMeEntry([Surface::Inertia])]), OwnPage::cases()))->toBe(['account.me reads actor.me@1'])
        ->and(QueryPageParity::broken(CompiledRegistry::empty(), OwnPage::cases()))->toBe(['account.me reads actor.me@1']);
});

it('accepts a registry with the query on REST, and the start page reads nothing', function (): void {
    expect(QueryPageParity::broken(new CompiledRegistry([], [], [actorMeEntry([Surface::Rest, Surface::Inertia])]), OwnPage::cases()))->toBe([])
        ->and(OwnPage::Home->query())->toBeNull()
        ->and(OwnPage::AccountMe->query()?->toString())->toBe('actor.me@1')
        ->and(OwnPage::named('account.me'))->toBe(OwnPage::AccountMe)
        ->and(OwnPage::named('tally.board'))->toBeNull();
});
