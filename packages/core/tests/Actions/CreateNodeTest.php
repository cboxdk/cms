<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Actions\CreateNodeAction;
use Cbox\Cms\Core\Tests\Structure\NodeActionWorld;
use ReflectionAttribute;
use ReflectionClass;

/*
 * node.create's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.8, 6.2): a create
 * reads the node as absent and the parent at its version and plans the node below the parent, on
 * the parent's path with the node's own label below it. It is unauthorized for a parent outside the
 * actor's regions, validation_failed for a parent that does not exist, one that is archived or a
 * mount, and for the kinds site and mount, and version_conflict for an id that exists and for a
 * parent that changed before the commit.
 */

it('plans the node below its parent and reads the node as absent and the parent at its version', function (): void {
    $world = new NodeActionWorld;

    $result = $world->create();
    $pending = $world->committed();
    [$mutation] = $pending->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('node.create')
        ->and($mutation)->toBeInstanceOf(NodeCreated::class)
        ->and($mutation instanceof NodeCreated ? [$mutation->node->toString(), $mutation->parent->toString(), $mutation->kind, $mutation->path->value] : [])
        ->toBe([
            NodeActionWorld::NODE,
            NodeActionWorld::SECTION,
            'section',
            $world->section->path->value.'.'.NodeCreated::label(NodeActionWorld::node(NodeActionWorld::NODE)),
        ])
        ->and(array_slice($world->reads(), -2))->toBe([
            'node:'.NodeActionWorld::SECTION.' '.NodeActionWorld::SECTION_VERSION,
            'node:'.NodeActionWorld::NODE.' -',
        ]);
});

it('creates a page, a list and a storage folder below a site root', function (NodeKind $kind): void {
    $world = new NodeActionWorld;

    $result = $world->create(NodeActionWorld::ROOT, $kind);
    [$mutation] = $world->committed()->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($mutation instanceof NodeCreated ? $mutation->kind : '')->toBe($kind->value);
})->with([[NodeKind::Page], [NodeKind::List], [NodeKind::Storage]]);

it('is unauthorized for a parent the actor\'s regions do not reach', function (): void {
    $world = new NodeActionWorld;

    $result = $world->create(NodeActionWorld::FAR);

    expect(NodeActionWorld::paths($result))->toBe(['unauthorized parent'])
        ->and($result->errors[0]->message)->toContain(NodeActionWorld::FAR)
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a parent that does not exist, one that is archived and one that is a mount', function (string $parent, string $message): void {
    $world = new NodeActionWorld;

    $result = $world->create($parent);

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed parent'])
        ->and($result->errors[0]->message)->toContain($message)
        ->and($world->committer->pending)->toBe([]);
})->with([
    [NodeActionWorld::NOWHERE, 'exists to create a node below'],
    [NodeActionWorld::ARCHIVED, 'is archived'],
    [NodeActionWorld::MOUNT, 'is a mount'],
]);

it('rejects the kinds site and mount, which a site\'s registration and mount.create make', function (NodeKind $kind): void {
    $world = new NodeActionWorld;

    $result = $world->create(kind: $kind);

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed kind'])
        ->and($result->errors[0]->message)->toContain($kind->value)
        ->and($world->committer->pending)->toBe([]);
})->with([[NodeKind::Site], [NodeKind::Mount]]);

it('is version_conflict for a node id that exists', function (): void {
    $world = new NodeActionWorld;

    $result = $world->create(node: NodeActionWorld::ARCHIVED);

    expect(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('node:'.NodeActionWorld::ARCHIVED)
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a node created below itself, which it reads once as the node it expects absent', function (): void {
    $world = new NodeActionWorld;

    $existing = $world->create(NodeActionWorld::SECTION, node: NodeActionWorld::SECTION);
    $absent = new NodeActionWorld()->create(NodeActionWorld::NODE, node: NodeActionWorld::NODE);

    expect(NodeActionWorld::codes($existing))->toBe(['version_conflict'])
        ->and(NodeActionWorld::paths($absent))->toBe(['validation_failed parent']);
});

it('is version_conflict when the parent changed before the commit', function (): void {
    $world = new NodeActionWorld;
    $world->committer->at(NodeActionWorld::node(NodeActionWorld::SECTION), new AggregateVersion(NodeActionWorld::SECTION_VERSION + 1));

    $result = $world->create();

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('changed after it was read');
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new NodeActionWorld;
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(new CreateNodeAction($world->nodes))->getAttributes(Action::class),
    );

    expect($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
