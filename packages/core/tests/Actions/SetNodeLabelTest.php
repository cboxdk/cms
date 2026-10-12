<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Action;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeLabelSet;
use Cbox\Cms\Core\Structure\Actions\SetNodeLabelAction;
use Cbox\Cms\Core\Structure\Domain\NodeLabel;
use Cbox\Cms\Core\Tests\Structure\NodeActionWorld;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;

/*
 * node.set_label's action in the command pipeline with fakes (GUARDRAILS 9, PRD 5.8, 6.2): a
 * label reads the node at its version and plans the label in the language. It is unauthorized for
 * a node outside the actor's regions, validation_failed for an archived node, and
 * version_conflict for a node that does not exist, one at another version and one that changed
 * before the commit. A label is 1 to 200 characters without control characters that starts and
 * ends with a character that is not white space.
 */

it('plans the label in the language and reads the node at its version', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setLabel(locale: 'en', label: 'News');
    $pending = $world->committed();
    [$mutation] = $pending->plan->mutations();

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($pending->command->value)->toBe('node.set_label')
        ->and($mutation)->toBeInstanceOf(NodeLabelSet::class)
        ->and($mutation instanceof NodeLabelSet ? [$mutation->node->toString(), $mutation->locale->value, $mutation->label] : [])
        ->toBe([NodeActionWorld::SECTION, 'en', 'News'])
        ->and(array_slice($world->reads(), -1))->toBe(['node:'.NodeActionWorld::SECTION.' '.NodeActionWorld::SECTION_VERSION]);
});

it('is unauthorized for a node the actor\'s regions do not reach', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setLabel(NodeActionWorld::FAR, 1);

    expect(NodeActionWorld::paths($result))->toBe(['unauthorized node'])
        ->and($result->errors[0]->message)->toContain(NodeActionWorld::FAR)
        ->and($world->committer->pending)->toBe([]);
});

it('rejects an archived node, which is read-only structure', function (): void {
    $world = new NodeActionWorld;

    $result = $world->setLabel(NodeActionWorld::ARCHIVED, 1);

    expect(NodeActionWorld::paths($result))->toBe(['validation_failed node'])
        ->and($result->errors[0]->message)->toContain('is archived')
        ->and($world->committer->pending)->toBe([]);
});

it('is version_conflict for a node that does not exist and for one at another version', function (string $node, int $version): void {
    $world = new NodeActionWorld;

    $result = $world->setLabel($node, $version);

    expect(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('node:'.$node)
        ->and($world->committer->pending)->toBe([]);
})->with([[NodeActionWorld::NOWHERE, 1], [NodeActionWorld::SECTION, NodeActionWorld::SECTION_VERSION + 1]]);

it('is version_conflict when the node changed before the commit', function (): void {
    $world = new NodeActionWorld;
    $world->committer->at(NodeActionWorld::node(NodeActionWorld::SECTION), new AggregateVersion(NodeActionWorld::SECTION_VERSION + 1));

    $result = $world->setLabel();

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(NodeActionWorld::codes($result))->toBe(['version_conflict'])
        ->and($result->errors[0]->message)->toContain('changed after it was read');
});

it('takes a label of 1 to 200 characters without control characters that starts and ends with a character that is not white space', function (): void {
    expect(new NodeLabel('Sport og fritid')->value)->toBe('Sport og fritid')
        ->and(new NodeLabel(str_repeat('a', 200))->value)->toHaveLength(200)
        ->and(new NodeLabel('Ø')->equals(new NodeLabel('Ø')))->toBeTrue();

    foreach (['', ' Sport', 'Sport ', "Sp\tort", str_repeat('a', 201)] as $bad) {
        expect(fn (): NodeLabel => new NodeLabel($bad))->toThrow(InvalidArgumentException::class);
    }
});

it('is exposed on the REST, Inertia, MCP and CLI surfaces', function (): void {
    $world = new NodeActionWorld;
    $surfaces = array_map(
        static fn (ReflectionAttribute $attribute): array => $attribute->newInstance()->surfaces,
        new ReflectionClass(new SetNodeLabelAction($world->nodes))->getAttributes(Action::class),
    );

    expect($surfaces)->toBe([[Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]]);
});
