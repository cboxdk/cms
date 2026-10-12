<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Plans\Mutations\MountOverrideSet;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Structure\Actions\SetMountOverrideAction;
use Cbox\Cms\Core\Structure\Domain\NodeLifecycle;
use Cbox\Cms\Core\Tests\Structure\NodeActionWorld;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;

/*
 * mount.set_override's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.8, 5.10,
 * 6.2, invariant 18): hiding reads the mount at its version, its override for the entry and what
 * the source shows of the entry, and plans the exception; un-hiding plans its removal, as one that
 * makes content public exactly when the source shows the entry now or later, which an agent may
 * not. It is unauthorized for a mount outside the actor's regions; validation_failed for a node
 * that is not a mount, an archived mount, an entry that does not exist, hiding what is hidden
 * already and un-hiding what is not hidden; and version_conflict for a mount that does not exist
 * or at another version.
 */

const OVERRIDE_ARCHIVED_MOUNT = '01936f5e-8a2b-7c3d-9e4f-000000000b25';

/**
 * The mutation of the one changeset the world committed.
 */
function overrideMutation(NodeActionWorld $world): MountOverrideSet
{
    [$mutation] = $world->committed()->plan->mutations();

    return $mutation instanceof MountOverrideSet ? $mutation : throw new LogicException('The plan is not an override.');
}

/**
 * A world whose mount hides ENTRY already.
 */
function hidingWorld(): NodeActionWorld
{
    $world = new NodeActionWorld;
    $world->nodes->withOverride(NodeActionWorld::node(NodeActionWorld::MOUNT), EntryId::fromString(NodeActionWorld::ENTRY));

    return $world;
}

it('hides the entry on the mount, reading the mount at its version, its override and what the source shows', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setOverride();
    $pending = $world->committed();
    $mutation = overrideMutation($world);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('mount.set_override')
        ->and([$mutation->mount->toString(), $mutation->entry->toString(), $mutation->hidden, $mutation->visible, $mutation->makesPublic()])
        ->toBe([NodeActionWorld::MOUNT, NodeActionWorld::ENTRY, true, true, false])
        ->and($mutation->aggregate()->aggregateKey())->toBe('node:'.NodeActionWorld::MOUNT)
        ->and(array_slice($world->reads(), -1))->toBe(['node:'.NodeActionWorld::MOUNT.' 1'])
        ->and($world->nodes->reads)->toContain('override '.NodeActionWorld::MOUNT.'|'.NodeActionWorld::ENTRY)
        ->and($world->nodes->reads)->toContain('mounted '.NodeActionWorld::SECTION.'|'.NodeActionWorld::ENTRY);
});

it('lets an agent hide an entry, which makes nothing public', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setOverride(agent: true);

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(overrideMutation($world)->makesPublic())->toBeFalse();
});

it('takes the exception off again, as one that makes content public while the source shows the entry', function (): void {
    $shown = hidingWorld();
    $unshown = hidingWorld();
    $unshown->nodes->withoutPlacements();

    $public = $shown->setOverride(hidden: false);
    $quiet = $unshown->setOverride(hidden: false);

    expect($public->outcome())->toBe(Outcome::Committed)
        ->and([overrideMutation($shown)->hidden, overrideMutation($shown)->visible, overrideMutation($shown)->makesPublic()])->toBe([false, true, true])
        ->and($quiet->outcome())->toBe(Outcome::Committed)
        ->and([overrideMutation($unshown)->hidden, overrideMutation($unshown)->visible, overrideMutation($unshown)->makesPublic()])->toBe([false, false, false]);
});

it('refuses an agent\'s credential and an agent\'s envelope un-hiding an entry the source shows (invariant 18), and lets an agent un-hide one it does not', function (bool $agent, bool $agentEnvelope): void {
    $shown = hidingWorld();
    $unshown = hidingWorld();
    $unshown->nodes->withoutPlacements();

    $refused = $shown->setOverride(hidden: false, agent: $agent, agentEnvelope: $agentEnvelope);
    $allowed = $unshown->setOverride(hidden: false, agent: $agent, agentEnvelope: $agentEnvelope);

    expect(NodeActionWorld::codes($refused))->toBe(['agent_visibility_forbidden'])
        ->and($refused->errors[0]->message)->toContain(MountOverrideSet::class)
        ->and($shown->committer->pending)->toBe([])
        ->and($allowed->outcome())->toBe(Outcome::Committed);
})->with([[true, false], [false, true]]);

it('is unauthorized for a mount the actor\'s regions do not reach', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setOverride(NodeActionWorld::FAR_MOUNT);

    expect(NodeActionWorld::paths($result))->toBe(['unauthorized mount'])
        ->and($result->errors[0]->message)->toContain(NodeActionWorld::FAR_MOUNT)
        ->and($world->committer->pending)->toBe([]);
});

it('rejects a node that is not a mount, an archived mount, an entry that does not exist, hiding what is hidden already and un-hiding what is not hidden', function (string $mount, int $version, string $entry, bool $hidden, bool $hiding, string $path, string $message): void {
    $world = $hiding ? hidingWorld() : new NodeActionWorld;
    $world->nodes->withNode(NodeActionWorld::node(OVERRIDE_ARCHIVED_MOUNT), $world->root, NodeKind::Mount, NodeLifecycle::Archived, mountSource: $world->section->id);

    $result = $world->setOverride($mount, $version, $entry, $hidden);

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed '.$path])
        ->and($result->errors[0]->message)->toContain($message)
        ->and($world->committer->pending)->toBe([]);
})->with([
    'not a mount' => [NodeActionWorld::SECTION, NodeActionWorld::SECTION_VERSION, NodeActionWorld::ENTRY, true, false, 'mount', 'is not a mount'],
    'an archived mount' => [OVERRIDE_ARCHIVED_MOUNT, 1, NodeActionWorld::ENTRY, true, false, 'mount', 'is archived'],
    'no such entry' => [NodeActionWorld::MOUNT, 1, NodeActionWorld::NO_ENTRY, true, false, 'entry', 'No entry'],
    'hidden already' => [NodeActionWorld::MOUNT, 1, NodeActionWorld::ENTRY, true, true, 'hidden', 'already'],
    'not hidden' => [NodeActionWorld::MOUNT, 1, NodeActionWorld::ENTRY, false, false, 'hidden', 'no exception to take off'],
]);

it('is version_conflict for a mount that does not exist and for one at another version', function (string $mount, int $version): void {
    $world = new NodeActionWorld;

    $result = $world->setOverride($mount, $version);

    expect(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('node:'.$mount)
        ->and($world->committer->pending)->toBe([]);
})->with([[NodeActionWorld::NOWHERE, 1], [NodeActionWorld::MOUNT, 2]]);

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new NodeActionWorld;
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(new SetMountOverrideAction($world->nodes, $world->clock))->getAttributes(Action::class),
    );

    expect($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
