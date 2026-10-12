<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Contracts\Plans\Mutations\NodeLabelSet;
use Cbox\Cms\Contracts\Plans\Mutations\NodeMoved;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteSet;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateNode;
use Cbox\Cms\Core\Structure\Domain\Commands\MoveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeLabel;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute;
use Cbox\Cms\Core\Structure\Domain\Events\NodeArchived;
use Cbox\Cms\Core\Structure\Domain\Events\NodeArchivedV1;
use Cbox\Cms\Core\Structure\Domain\Events\NodeLabelChanged;
use Cbox\Cms\Core\Structure\Domain\Events\NodeLabelChangedV1;
use Cbox\Cms\Core\Structure\Domain\Events\NodeMoved as NodeMovedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\NodeMovedV1;
use Cbox\Cms\Core\Structure\Domain\Events\NodeRouteChanged;
use Cbox\Cms\Core\Structure\Domain\Events\NodeRouteChangedV1;
use Cbox\Cms\Core\Structure\Domain\NodeLabel;

// A newsroom gives its site a section for sport: node.create puts the node below the site's root,
// node.set_route gives it the URL /sport in Danish, node.set_label the text a breadcrumb shows,
// node.move takes it below another section with everything below it, and node.archive closes it
// again once nothing below it is visible. The ids are the caller's, so a repeat with the same
// idempotency key is the same node.

const NODE_EXAMPLE_SITE = '01936f5e-8a2b-7c3d-9e4f-000000000d01';

const NODE_EXAMPLE_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000d02';

const NODE_EXAMPLE_SECTION = '01936f5e-8a2b-7c3d-9e4f-000000000d03';

const NODE_EXAMPLE_OTHER = '01936f5e-8a2b-7c3d-9e4f-000000000d04';

it('creates a node below a parent and expects the node absent', function (): void {
    $create = new CreateNode(NodeId::fromString(NODE_EXAMPLE_SECTION), NodeId::fromString(NODE_EXAMPLE_ROOT), NodeKind::Section);
    $expected = $create->expectedVersions()->reads;

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey(), $expected))
        ->toBe(['node:'.NODE_EXAMPLE_SECTION])
        ->and(array_map(static fn (ReadVersion $read): bool => $read->existed(), $expected))->toBe([false])
        ->and($create->kind->value)->toBe('section');
});

it('puts a new node on its parent\'s path with its own label below it', function (): void {
    $node = NodeId::fromString(NODE_EXAMPLE_SECTION);
    $parent = NodeId::fromString(NODE_EXAMPLE_ROOT);
    $path = new NodePath(NodeCreated::label($parent).'.'.NodeCreated::label($node));
    $created = new NodeCreated($node, $parent, 'section', $path);

    expect(NodeCreated::KINDS)->toBe(['section', 'page', 'list', 'storage'])
        ->and($created->path->value)->toBe($path->value)
        ->and($created->aggregate()->aggregateKey())->toBe('node:'.NODE_EXAMPLE_SECTION)
        ->and(fn (): NodeCreated => new NodeCreated($node, $parent, 'site', $path))
        ->toThrow(InvalidArgumentException::class, 'a node below another is one of section, page, list, storage');
});

it('gives the node a route the public asks for, which only a person may do', function (): void {
    $route = new SetNodeRoute(
        NodeId::fromString(NODE_EXAMPLE_SECTION),
        new AggregateVersion(1),
        SiteId::fromString(NODE_EXAMPLE_SITE),
        new Locale('da'),
        new RequestPath('/sport'),
    );
    $mutation = new NodeRouteSet($route->node, $route->site, $route->locale, $route->route->value);

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey(), $route->expectedVersions()->reads))
        ->toBe(['node:'.NODE_EXAMPLE_SECTION])
        ->and($mutation->route)->toBe('/sport')
        ->and($mutation->makesPublic())->toBeTrue()
        ->and(ErrorCode::NodeRouteTaken->entry()->http)->toBe(HttpStatus::Conflict);
});

it('archives a node at the version the caller read, and tells about it with ids alone', function (): void {
    $archive = new ArchiveNode(NodeId::fromString(NODE_EXAMPLE_SECTION), new AggregateVersion(2));
    $archived = new NodeArchived(3, new NodeArchivedV1($archive->node));
    $changed = new NodeRouteChanged(2, new NodeRouteChangedV1($archive->node, SiteId::fromString(NODE_EXAMPLE_SITE), new Locale('da')));

    expect(array_map(static fn (ReadVersion $read): ?int => $read->version?->value, $archive->expectedVersions()->reads))->toBe([2])
        ->and(NodeArchived::type()->name)->toBe('node.archived')
        ->and($archived->aggregate()->version)->toBe(3)
        ->and(NodeRouteChanged::type()->name)->toBe('node.route_changed')
        ->and(array_keys($changed->payload()->data()->fields()))->toBe(['locale', 'node', 'site']);
});

it('moves the node with its subtree below another parent, which makes content public only across sites', function (): void {
    $node = NodeId::fromString(NODE_EXAMPLE_SECTION);
    $move = new MoveNode($node, new AggregateVersion(2), NodeId::fromString(NODE_EXAMPLE_OTHER));
    $path = new NodePath(NodeCreated::label(NodeId::fromString(NODE_EXAMPLE_ROOT)).'.'.NodeCreated::label($move->parent).'.'.NodeCreated::label($node));
    $within = new NodeMoved($node, NodeId::fromString(NODE_EXAMPLE_ROOT), $move->parent, $path, crossesSites: false, visible: true);
    $across = new NodeMoved($node, NodeId::fromString(NODE_EXAMPLE_ROOT), $move->parent, $path, crossesSites: true, visible: true);
    $moved = new NodeMovedEvent(3, new NodeMovedV1($node, $within->from, $within->to));

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey(), $move->expectedVersions()->reads))
        ->toBe(['node:'.NODE_EXAMPLE_SECTION])
        ->and($within->makesPublic())->toBeFalse()
        ->and($across->makesPublic())->toBeTrue()
        ->and(NodeMovedEvent::type()->name)->toBe('node.moved')
        ->and(array_keys($moved->payload()->data()->fields()))->toBe(['from', 'node', 'to']);
});

it('gives the node a label in one language, which no event repeats', function (): void {
    $node = NodeId::fromString(NODE_EXAMPLE_SECTION);
    $label = new SetNodeLabel($node, new AggregateVersion(3), new Locale('da'), new NodeLabel('Sport'));
    $set = new NodeLabelSet($label->node, $label->locale, $label->label->value);
    $changed = new NodeLabelChanged(4, new NodeLabelChangedV1($node, $label->locale));

    expect($set->label)->toBe('Sport')
        ->and(NodeLabelSet::MAX_LENGTH)->toBe(200)
        ->and(fn (): NodeLabel => new NodeLabel(' Sport'))->toThrow(InvalidArgumentException::class)
        ->and(NodeLabelChanged::type()->name)->toBe('node.label_changed')
        ->and(array_keys($changed->payload()->data()->fields()))->toBe(['locale', 'node']);
});
