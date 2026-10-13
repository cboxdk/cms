<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteMoved;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteSet;
use Cbox\Cms\Core\Structure\Actions\ChangeNodeRouteAction;
use Cbox\Cms\Core\Tests\Structure\NodeActionWorld;
use ReflectionAttribute;
use ReflectionClass;

/*
 * node.set_route version 2's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.9,
 * 6.2, invariant 18): it reads what version 1 reads and plans a NodeRouteMoved for a node that has
 * a route on the site in the language, which records the old route as a prefix redirect and makes
 * nothing public, so an agent may move a route, and a NodeRouteSet for a node without one, which
 * an agent may not plan. It is unauthorized for a node outside the actor's regions,
 * node_route_taken for a route another node holds, validation_failed for the route the node has
 * already, an archived node, a site that does not exist, a language the site does not publish in
 * and a node outside the site's tree, and version_conflict for a node at another version and for
 * a route claimed before the commit.
 */

it('moves the route of a node that has one, also from an agent, and reads the node, the site and the new route as free', function (): void {
    $world = new NodeActionWorld;

    $result = $world->changeRoute(agent: true);
    $pending = $world->committed();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('node.set_route')
        ->and($pending->version)->toBe(2)
        ->and($pending->plan->mutations())->toEqual([new NodeRouteMoved(NodeActionWorld::node(NodeActionWorld::SECTION), NodeActionWorld::site(NodeActionWorld::NORTH), new Locale('da'), '/nyheder', '/aktuelt')])
        ->and(array_slice($world->reads(), -3))->toBe([
            'node:'.NodeActionWorld::SECTION.' '.NodeActionWorld::SECTION_VERSION,
            'node_route:'.NodeActionWorld::NORTH.':da:/aktuelt -',
            'site:'.NodeActionWorld::NORTH.' 1',
        ]);
});

it('sets the route of a node without one as version 1 does, which an agent may not', function (): void {
    $person = new NodeActionWorld;
    $agent = new NodeActionWorld;

    $set = $person->changeRoute(NodeActionWorld::SPARE, 1, '/sport');
    $refused = $agent->changeRoute(NodeActionWorld::SPARE, 1, '/sport', agent: true);
    [$mutation] = $person->committed()->plan->mutations();

    expect($set->outcome())->toBe(Outcome::Committed)
        ->and($mutation)->toBeInstanceOf(NodeRouteSet::class)
        ->and(NodeActionWorld::codes($refused))->toBe(['agent_visibility_forbidden'])
        ->and($agent->committer->pending)->toBe([]);
});

it('refuses the route the node has already', function (): void {
    $world = new NodeActionWorld;

    $result = $world->changeRoute(route: '/nyheder');

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed route'])
        ->and($result->errors[0]->message)->toContain('already')
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a route another node holds with node_route_taken', function (): void {
    $world = new NodeActionWorld;

    $result = $world->changeRoute(route: '/');

    expect(NodeActionWorld::paths($result))->toBe(['node_route_taken route'])
        ->and($result->errors[0]->message)->toContain(NodeActionWorld::ROOT)
        ->and($world->committer->pending)->toBe([]);
});

it('is unauthorized for a node the actor\'s regions do not reach, and refuses an archived node', function (): void {
    $world = new NodeActionWorld;

    $far = $world->changeRoute(NodeActionWorld::FAR, 1, site: NodeActionWorld::SOUTH);
    $archived = $world->changeRoute(NodeActionWorld::ARCHIVED, 1);

    expect(NodeActionWorld::paths($far))->toBe(['unauthorized node'])
        ->and(NodeActionWorld::paths($archived))->toBe(['validation_failed node'])
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a site the installation does not have, a language it does not publish in and a node outside its tree', function (): void {
    $world = new NodeActionWorld;

    $noSite = $world->changeRoute(site: NodeActionWorld::NOWHERE);
    $noLocale = $world->changeRoute(locale: 'de');
    $otherTree = $world->changeRoute(site: NodeActionWorld::WEST);

    expect(NodeActionWorld::paths($noSite))->toBe(['validation_failed site'])
        ->and(NodeActionWorld::paths($noLocale))->toBe(['validation_failed locale'])
        ->and(NodeActionWorld::paths($otherTree))->toBe(['validation_failed node'])
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for a node at another version and for a route claimed before the commit', function (): void {
    $stale = new NodeActionWorld;
    $claimed = new NodeActionWorld;
    $claimed->committer->at(NodeActionWorld::routeRef('/aktuelt'), AggregateVersion::first());

    $staleResult = $stale->changeRoute(version: NodeActionWorld::SECTION_VERSION + 1);
    $claimedResult = $claimed->changeRoute();

    expect(NodeActionWorld::codes($staleResult))->toBe(['version_conflict'])
        ->and($stale->committer->pending)->toBe([])
        ->and(NodeActionWorld::codes($claimedResult))->toBe(['version_conflict'])
        ->and($claimedResult->errors[0]->message)->toContain('node_route:'.NodeActionWorld::NORTH.':da:/aktuelt');
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new NodeActionWorld;
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(new ChangeNodeRouteAction($world->nodes, $world->sites))->getAttributes(Action::class),
    );

    expect($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
