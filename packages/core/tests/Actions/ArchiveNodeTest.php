<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeArchived;
use Cbox\Cms\Core\Structure\Actions\ArchiveNodeAction;
use Cbox\Cms\Core\Tests\Structure\NodeActionWorld;
use DateTimeImmutable;
use ReflectionAttribute;
use ReflectionClass;

/*
 * node.archive's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.8, 6.4, 6.2): an
 * archive reads the node at its version and the placements below it at the Clock's time, and plans
 * the node archived. It is unauthorized for a node outside the actor's regions, validation_failed
 * for a node that is archived already, for a site root and while a placement below the node is
 * visible now or later, and version_conflict for a node that does not exist, one at another
 * version and one that changed before the commit.
 */

it('plans the node archived and reads it at its version', function (): void {
    $world = new NodeActionWorld;
    $world->nodes->withoutPlacements();

    $result = $world->archive();
    $pending = $world->committed();
    [$mutation] = $pending->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('node.archive')
        ->and($mutation)->toBeInstanceOf(NodeArchived::class)
        ->and($mutation instanceof NodeArchived ? $mutation->node->toString() : '')->toBe(NodeActionWorld::SECTION)
        ->and(array_slice($world->reads(), -1))->toBe(['node:'.NodeActionWorld::SECTION.' '.NodeActionWorld::SECTION_VERSION])
        ->and($world->nodes->reads)->toContain('placement '.NodeActionWorld::SECTION);
});

it('is unauthorized for a node the actor\'s regions do not reach', function (): void {
    $world = new NodeActionWorld;

    $result = $world->archive(NodeActionWorld::FAR, 1);

    expect(NodeActionWorld::codes($result))->toBe(['unauthorized'])
        ->and($result->errors[0]->message)->toContain(NodeActionWorld::FAR)
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a node while a placement below it is visible now or later', function (): void {
    $world = new NodeActionWorld;

    $result = $world->archive();

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed node'])
        ->and($result->errors[0]->message)->toContain(NodeActionWorld::LIVE)
        ->and($result->errors[0]->message)->toContain('unpublish the content first')
        ->and($world->committer->pending)->toBe([]);
});

it('refuses a node whose placement is visible later, and archives one whose window has ended', function (): void {
    $later = new NodeActionWorld;
    $later->nodes->withoutPlacements();
    $later->nodes->withPlacement(PlacementId::fromString(NodeActionWorld::LIVE), $later->section, new DateTimeImmutable('2026-04-01T00:00:00Z'));

    $ended = new NodeActionWorld;
    $ended->nodes->withoutPlacements();
    $ended->nodes->withPlacement(PlacementId::fromString(NodeActionWorld::LIVE), $ended->section, new DateTimeImmutable('2026-03-01T00:00:00Z'));

    expect(NodeActionWorld::codes($later->archive()))->toBe(['validation_failed'])
        ->and($ended->archive()->outcome())->toBe(Outcome::Committed);
});

it('refuses a node that is archived already and the root of a site', function (): void {
    $world = new NodeActionWorld;
    $world->nodes->withoutPlacements();

    $archived = $world->archive(NodeActionWorld::ARCHIVED, 1);
    $root = new NodeActionWorld;
    $root->nodes->withoutPlacements();

    expect(NodeActionWorld::paths($archived))->toBe(['validation_failed node'])
        ->and($archived->errors[0]->message)->toContain('is archived already')
        ->and(NodeActionWorld::paths($root->archive(NodeActionWorld::ROOT, 1)))->toBe(['validation_failed node'])
        ->and($root->archive(NodeActionWorld::ROOT, 1)->errors[0]->message)->toContain('root of a site');
});

it('is version_conflict for a node that does not exist and for one at another version', function (): void {
    $world = new NodeActionWorld;

    $missing = $world->archive(NodeActionWorld::NOWHERE, 1);
    $stale = $world->archive(NodeActionWorld::SECTION, NodeActionWorld::SECTION_VERSION + 1);

    expect(NodeActionWorld::codes($missing))->toBe(['version_conflict'])
        ->and(NodeActionWorld::codes($stale))->toBe(['version_conflict'])
        ->and($stale->errors[0]->message)->toContain('is not at the version the caller saw')
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict when the node changed before the commit', function (): void {
    $world = new NodeActionWorld;
    $world->nodes->withoutPlacements();
    $world->committer->at(NodeActionWorld::node(NodeActionWorld::SECTION), new AggregateVersion(NodeActionWorld::SECTION_VERSION + 1));

    $result = $world->archive();

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('changed after it was read');
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new NodeActionWorld;
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(new ArchiveNodeAction($world->nodes, $world->clock))->getAttributes(Action::class),
    );

    expect($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
