<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\MountCreated;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Core\Structure\Actions\CreateMountAction;
use Cbox\Cms\Core\Tests\Structure\NodeActionWorld;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;

/*
 * mount.create's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.8, 5.10, 6.2): a
 * create reads the mount node, which it expects absent, the parent, the source and every ancestor
 * of the parent, each once, and plans the mount below the parent on the parent's path with the
 * mount's own label below it, showing the source. It is unauthorized for a parent outside the
 * actor's regions, validation_failed for a parent that does not exist, is archived or is a mount,
 * and for a source that does not exist, is the mount itself, is a mount, is archived, or is the
 * parent or above it; and version_conflict for a mount id that exists, and for a parent, a source
 * or an ancestor that changed before the commit, which is what stops a node.move from putting the
 * source above the mount meanwhile.
 */

/**
 * The mutation of the one changeset the world committed.
 */
function mountedMutation(NodeActionWorld $world): MountCreated
{
    [$mutation] = $world->committed()->plan->mutations();

    return $mutation instanceof MountCreated ? $mutation : throw new LogicException('The plan is not a mount.');
}

it('plans the mount below the parent showing the source, and reads the node, the parent, the source and the ancestors once each', function (): void {
    $world = new NodeActionWorld;

    $result = $world->createMount();
    $pending = $world->committed();
    $mutation = mountedMutation($world);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('mount.create')
        ->and([$mutation->node->toString(), $mutation->parent->toString(), $mutation->source->toString(), $mutation->path->value])
        ->toBe([
            NodeActionWorld::NODE,
            NodeActionWorld::SECTION,
            NodeActionWorld::SPARE,
            $world->section->path->value.'.'.NodeCreated::label(NodeActionWorld::node(NodeActionWorld::NODE)),
        ])
        ->and($mutation->aggregate()->aggregateKey())->toBe('node:'.NodeActionWorld::NODE)
        ->and(array_slice($world->reads(), -4))->toBe([
            'node:'.NodeActionWorld::ROOT.' 1',
            'node:'.NodeActionWorld::SECTION.' '.NodeActionWorld::SECTION_VERSION,
            'node:'.NodeActionWorld::SPARE.' 1',
            'node:'.NodeActionWorld::NODE.' -',
        ])
        ->and($world->nodes->reads)->toContain('ancestors '.NodeActionWorld::SECTION);
});

it('lets an agent create a mount, which makes nothing public of its own', function (): void {
    $world = new NodeActionWorld;

    $result = $world->createMount(agent: true);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(mountedMutation($world)->source->toString())->toBe(NodeActionWorld::SPARE);
});

it('reads a source that is the parent once, and refuses it', function (): void {
    $world = new NodeActionWorld;

    $result = $world->createMount(NodeActionWorld::SECTION, NodeActionWorld::SECTION);

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed source'])
        ->and($result->errors[0]->message)->toContain('below its own source')
        ->and(array_count_values($world->nodes->reads)['node '.NodeActionWorld::SECTION])->toBe(1)
        ->and($world->committer->pending)->toBe([]);
});

it('is unauthorized for a parent the actor\'s regions do not reach, also when the source is reached', function (): void {
    $world = new NodeActionWorld;

    $result = $world->createMount(NodeActionWorld::FAR_CHILD, NodeActionWorld::SECTION);

    expect(NodeActionWorld::paths($result))->toBe(['unauthorized parent'])
        ->and($result->errors[0]->message)->toContain(NodeActionWorld::FAR_CHILD)
        ->and($world->committer->pending)->toBe([]);
});

it('creates a mount of a source outside the actor\'s regions, because a mount shows only what the source shows the public', function (): void {
    $world = new NodeActionWorld;

    $result = $world->createMount(NodeActionWorld::SECTION, NodeActionWorld::FAR_CHILD);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(mountedMutation($world)->source->toString())->toBe(NodeActionWorld::FAR_CHILD);
});

it('rejects a parent that does not exist, is archived or is a mount, and a source that does not exist, is the mount itself, is a mount, is archived, or is the parent or above it', function (string $parent, string $source, string $path, string $message): void {
    $world = new NodeActionWorld;

    $result = $world->createMount($parent, $source);

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed '.$path])
        ->and($result->errors[0]->message)->toContain($message)
        ->and($world->committer->pending)->toBe([]);
})->with([
    'a parent that does not exist' => [NodeActionWorld::NOWHERE, NodeActionWorld::SPARE, 'parent', 'exists to create a mount below'],
    'an archived parent' => [NodeActionWorld::ARCHIVED, NodeActionWorld::SPARE, 'parent', 'is archived'],
    'a mount as parent' => [NodeActionWorld::MOUNT, NodeActionWorld::SPARE, 'parent', 'is a mount'],
    'a source that does not exist' => [NodeActionWorld::SECTION, NodeActionWorld::NOWHERE, 'source', 'exists for the mount to show'],
    'the mount itself as source' => [NodeActionWorld::SECTION, NodeActionWorld::NODE, 'source', 'names itself'],
    'a mount as source' => [NodeActionWorld::SECTION, NodeActionWorld::MOUNT, 'source', 'not another mount'],
    'an archived source' => [NodeActionWorld::SECTION, NodeActionWorld::ARCHIVED, 'source', 'is archived'],
    'the parent as source' => [NodeActionWorld::SECTION, NodeActionWorld::SECTION, 'source', 'below its own source'],
    'an ancestor as source' => [NodeActionWorld::CHILD, NodeActionWorld::ROOT, 'source', 'below its own source'],
]);

it('is version_conflict for a mount id that exists', function (): void {
    $world = new NodeActionWorld;

    $result = $world->createMount(node: NodeActionWorld::SPARE);

    expect(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('node:'.NodeActionWorld::SPARE)
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when the parent, the source or an ancestor changed before the commit, which is what stops a move that puts the source above the mount', function (string $changed): void {
    $world = new NodeActionWorld;
    $world->committer->at(NodeActionWorld::node($changed), new AggregateVersion(5));

    $result = $world->createMount();

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('node:'.$changed);
})->with(['the parent' => [NodeActionWorld::SECTION], 'the source' => [NodeActionWorld::SPARE], 'an ancestor' => [NodeActionWorld::ROOT]]);

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new NodeActionWorld;
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(new CreateMountAction($world->nodes))->getAttributes(Action::class),
    );

    expect($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
