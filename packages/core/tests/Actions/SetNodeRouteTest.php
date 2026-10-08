<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteSet;
use Cbox\Cms\Core\Structure\Actions\SetNodeRouteAction;
use Cbox\Cms\Core\Tests\Structure\NodeActionWorld;
use ReflectionAttribute;
use ReflectionClass;

/*
 * node.set_route's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.9, 6.2,
 * invariant 18): a set_route reads the node at its version, the site, the node that holds the route
 * and the route the node has already, and plans the route. It is unauthorized for a node outside
 * the actor's regions, node_route_taken for a route another node holds, validation_failed for an
 * archived node, a mount, a site that does not exist, a language the site does not publish in, a
 * node outside the site's tree and a node that has a route there already, version_conflict for a
 * node at another version and for a route claimed before the commit, and
 * agent_visibility_forbidden for an agent, because a route makes the node public.
 */

it('plans the route of the node and reads the node, the site and the route', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setRoute();
    $pending = $world->committed();
    [$mutation] = $pending->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('node.set_route')
        ->and($mutation)->toBeInstanceOf(NodeRouteSet::class)
        ->and($mutation instanceof NodeRouteSet ? [$mutation->node->toString(), $mutation->site->toString(), $mutation->locale->value, $mutation->route] : [])
        ->toBe([NodeActionWorld::SPARE, NodeActionWorld::NORTH, 'da', '/sport'])
        ->and($mutation instanceof NodeRouteSet ? $mutation->makesPublic() : null)->toBeTrue()
        ->and(array_slice($world->reads(), -3))->toBe([
            'node:'.NodeActionWorld::SPARE.' 1',
            'node_route:'.NodeActionWorld::NORTH.':da:/sport -',
            'site:'.NodeActionWorld::NORTH.' 1',
        ]);
});

it('is unauthorized for a node the actor\'s regions do not reach', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setRoute(NodeActionWorld::FAR, site: NodeActionWorld::SOUTH);

    expect(NodeActionWorld::paths($result))->toBe(['unauthorized node'])
        ->and($result->errors[0]->message)->toContain(NodeActionWorld::FAR)
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a route another node holds with node_route_taken', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setRoute(route: '/nyheder');

    expect(NodeActionWorld::paths($result))->toBe(['node_route_taken route'])
        ->and($result->errors[0]->message)->toContain(NodeActionWorld::SECTION)
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a node that has a route on the site in the language already', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setRoute(NodeActionWorld::SECTION, NodeActionWorld::SECTION_VERSION, '/sport');

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed route'])
        ->and($result->errors[0]->message)->toContain('/nyheder')
        ->and($result->errors[0]->message)->toContain('redirect manager')
        ->and($world->committer->pending)->toBe([]);
});

it('refuses an archived node and a mount', function (string $node, string $message): void {
    $world = new NodeActionWorld;

    $result = $world->setRoute($node);

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed node'])
        ->and($result->errors[0]->message)->toContain($message)
        ->and($world->committer->pending)->toBe([]);
})->with([
    [NodeActionWorld::ARCHIVED, 'is archived'],
    [NodeActionWorld::MOUNT, 'is a mount'],
]);

it('refuses a site the installation does not have, a language it does not publish in and a node outside its tree', function (): void {
    $world = new NodeActionWorld;

    $unknown = $world->setRoute(site: NodeActionWorld::NOWHERE);
    $language = $world->setRoute(locale: 'de');
    $outside = $world->setRoute(site: NodeActionWorld::SOUTH);

    expect(NodeActionWorld::paths($unknown))->toBe(['validation_failed site'])
        ->and(NodeActionWorld::paths($language))->toBe(['validation_failed locale'])
        ->and($language->errors[0]->message)->toContain('does not publish in de')
        ->and(NodeActionWorld::paths($outside))->toBe(['validation_failed node'])
        ->and($outside->errors[0]->message)->toContain('not below the root of the site')
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a route from an agent\'s credential and from an envelope that records an agent (invariant 18)', function (): void {
    $credential = new NodeActionWorld;
    $envelope = new NodeActionWorld;

    $byCredential = $credential->setRoute(agent: true);
    $byEnvelope = $envelope->setRoute(agentEnvelope: true);

    expect(NodeActionWorld::codes($byCredential))->toBe(['agent_visibility_forbidden'])
        ->and($byCredential->errors[0]->message)->toContain(NodeRouteSet::class)
        ->and(NodeActionWorld::codes($byEnvelope))->toBe(['agent_visibility_forbidden'])
        ->and($credential->committer->pending)->toBe([])
        ->and($envelope->committer->pending)->toBe([]);
});

it('is version_conflict for a node at another version', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setRoute(version: 2);

    expect(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('is not at the version the caller saw')
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when another command claimed the route before the commit', function (): void {
    $world = new NodeActionWorld;
    $world->committer->at(NodeActionWorld::routeRef('/sport'), AggregateVersion::first());

    $result = $world->setRoute();

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('node_route:'.NodeActionWorld::NORTH.':da:/sport');
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new NodeActionWorld;
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(new SetNodeRouteAction($world->nodes, $world->sites))->getAttributes(Action::class),
    );

    expect($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
