<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Contracts\Plans\Mutations\NodeMoved;
use Cbox\Cms\Core\Structure\Actions\MoveNodeAction;
use Cbox\Cms\Core\Tests\Structure\NodeActionWorld;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;

/*
 * node.move's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.8, 5.10, 6.2,
 * invariant 18): a move reads the node at its version, both parents and every ancestor of the new
 * parent, each once, and plans the node below the new parent on the parent's path with the node's
 * own label below it. It is unauthorized for a node or a parent outside the actor's regions,
 * validation_failed for a site root, an archived node, a parent that does not exist, is archived
 * or a mount, a parent that is the node itself or below it, the parent the node is below already,
 * and a move to another site while a node of the subtree has a route; agent_visibility_forbidden
 * for an agent's move of a subtree with a visible placement to another site; and version_conflict
 * for a node that does not exist or at another version, and for a parent or an ancestor that
 * changed before the commit.
 */

/**
 * The mutation of the one changeset the world committed.
 */
function movedMutation(NodeActionWorld $world): NodeMoved
{
    [$mutation] = $world->committed()->plan->mutations();

    return $mutation instanceof NodeMoved ? $mutation : throw new LogicException('The plan is not a move.');
}

it('plans the node below its new parent and reads the node, both parents and the ancestors of the new parent once each', function (): void {
    $world = new NodeActionWorld;

    $result = $world->move();
    $pending = $world->committed();
    $mutation = movedMutation($world);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('node.move')
        ->and([$mutation->node->toString(), $mutation->from->toString(), $mutation->to->toString(), $mutation->path->value, $mutation->crossesSites, $mutation->visible])
        ->toBe([
            NodeActionWorld::SPARE,
            NodeActionWorld::ROOT,
            NodeActionWorld::SECTION,
            $world->section->path->value.'.'.NodeCreated::label(NodeActionWorld::node(NodeActionWorld::SPARE)),
            false,
            false,
        ])
        ->and($mutation->makesPublic())->toBeFalse()
        ->and(array_slice($world->reads(), -3))->toBe([
            'node:'.NodeActionWorld::ROOT.' 1',
            'node:'.NodeActionWorld::SECTION.' '.NodeActionWorld::SECTION_VERSION,
            'node:'.NodeActionWorld::SPARE.' 1',
        ])
        ->and($world->nodes->reads)->toContain('ancestors '.NodeActionWorld::SECTION)
        ->and($world->nodes->reads)->not->toContain('routed '.NodeActionWorld::SPARE)
        ->and($world->nodes->reads)->not->toContain('placement '.NodeActionWorld::SPARE);
});

it('lets an agent move a node within its site, which makes nothing public', function (): void {
    $world = new NodeActionWorld;

    $result = $world->move(agent: true);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(movedMutation($world)->makesPublic())->toBeFalse();
});

it('is unauthorized for a node, or a parent, the actor\'s regions do not reach', function (string $node, string $parent, string $path, string $named): void {
    $world = new NodeActionWorld;

    $result = $world->move($node, 1, $parent);

    expect(NodeActionWorld::paths($result))->toBe(['unauthorized '.$path])
        ->and($result->errors[0]->message)->toContain($named)
        ->and($world->committer->pending)->toBe([]);
})->with([
    'the node' => [NodeActionWorld::FAR_CHILD, NodeActionWorld::SECTION, 'node', NodeActionWorld::FAR_CHILD],
    'the new parent' => [NodeActionWorld::SPARE, NodeActionWorld::FAR, 'parent', NodeActionWorld::FAR],
]);

it('rejects a site root, an archived node and a parent that does not exist, is archived, is a mount, is the node itself or below it, or is the parent already', function (string $node, int $version, string $parent, string $path, string $message): void {
    $world = new NodeActionWorld;

    $result = $world->move($node, $version, $parent);

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed '.$path])
        ->and($result->errors[0]->message)->toContain($message)
        ->and($world->committer->pending)->toBe([]);
})->with([
    'a site root' => [NodeActionWorld::ROOT, 1, NodeActionWorld::SECTION, 'node', 'root of a site'],
    'an archived node' => [NodeActionWorld::ARCHIVED, 1, NodeActionWorld::SPARE, 'node', 'is archived'],
    'a parent that does not exist' => [NodeActionWorld::SPARE, 1, NodeActionWorld::NOWHERE, 'parent', 'exists to move the node below'],
    'an archived parent' => [NodeActionWorld::SPARE, 1, NodeActionWorld::ARCHIVED, 'parent', 'is archived'],
    'a mount' => [NodeActionWorld::SPARE, 1, NodeActionWorld::MOUNT, 'parent', 'is a mount'],
    'the node itself' => [NodeActionWorld::SECTION, NodeActionWorld::SECTION_VERSION, NodeActionWorld::SECTION, 'parent', 'itself or below it'],
    'a descendant' => [NodeActionWorld::SECTION, NodeActionWorld::SECTION_VERSION, NodeActionWorld::CHILD, 'parent', 'itself or below it'],
    'the parent already' => [NodeActionWorld::SPARE, 1, NodeActionWorld::ROOT, 'parent', 'already'],
]);

it('rejects a move to another site while a node of the subtree has a route, naming the routed node', function (): void {
    $world = new NodeActionWorld;

    $result = $world->move(NodeActionWorld::SECTION, NodeActionWorld::SECTION_VERSION, NodeActionWorld::WEST_ROOT);

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed parent'])
        ->and($result->errors[0]->message)->toContain('has a route there')
        ->and($result->errors[0]->message)->toContain(NodeActionWorld::SECTION)
        ->and($world->nodes->reads)->toContain('routed '.NodeActionWorld::SECTION)
        ->and($world->committer->pending)->toBe([]);
});

it('moves a subtree without a route to another site, and plans it as public only while a placement below it is visible', function (): void {
    $hidden = new NodeActionWorld;
    $hidden->nodes->withoutPlacements();
    $shown = new NodeActionWorld;

    $quiet = $hidden->move(NodeActionWorld::SPARE, 1, NodeActionWorld::WEST_ROOT);
    $public = $shown->move(NodeActionWorld::SPARE, 1, NodeActionWorld::WEST_ROOT);

    expect($quiet->outcome())->toBe(Outcome::Committed)
        ->and([movedMutation($hidden)->crossesSites, movedMutation($hidden)->visible, movedMutation($hidden)->makesPublic()])->toBe([true, false, false])
        ->and($public->outcome())->toBe(Outcome::Committed)
        ->and([movedMutation($shown)->crossesSites, movedMutation($shown)->visible, movedMutation($shown)->makesPublic()])->toBe([true, true, true])
        ->and(movedMutation($shown)->path->value)->toBe($shown->westRoot->path->value.'.'.NodeCreated::label(NodeActionWorld::node(NodeActionWorld::SPARE)))
        ->and($shown->nodes->reads)->toContain('placement '.NodeActionWorld::SPARE);
});

it('refuses an agent\'s credential and an agent\'s envelope a move that takes a visible placement to another site (invariant 18)', function (bool $agent, bool $agentEnvelope): void {
    $world = new NodeActionWorld;

    $result = $world->move(NodeActionWorld::SPARE, 1, NodeActionWorld::WEST_ROOT, $agent, $agentEnvelope);

    expect(NodeActionWorld::codes($result))->toBe(['agent_visibility_forbidden'])
        ->and($result->errors[0]->message)->toContain(NodeMoved::class)
        ->and($world->committer->pending)->toBe([]);
})->with([[true, false], [false, true]]);

it('is version_conflict for a node that does not exist and for one at another version', function (string $node, int $version): void {
    $world = new NodeActionWorld;

    $result = $world->move($node, $version);

    expect(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('node:'.$node)
        ->and($world->committer->pending)->toBe([]);
})->with([[NodeActionWorld::NOWHERE, 1], [NodeActionWorld::SPARE, 2]]);

it('is version_conflict when the new parent or an ancestor of it changed before the commit, which is what stops two moves that would make a cycle', function (string $changed): void {
    $world = new NodeActionWorld;
    $world->committer->at(NodeActionWorld::node($changed), new AggregateVersion(5));

    $result = $world->move();

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('node:'.$changed);
})->with(['the new parent' => [NodeActionWorld::SECTION], 'an ancestor of it' => [NodeActionWorld::ROOT]]);

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new NodeActionWorld;
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(new MoveNodeAction($world->nodes, $world->clock))->getAttributes(Action::class),
    );

    expect($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
