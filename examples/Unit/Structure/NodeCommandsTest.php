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
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteSet;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateNode;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute;
use Cbox\Cms\Core\Structure\Domain\Events\NodeArchived;
use Cbox\Cms\Core\Structure\Domain\Events\NodeArchivedV1;
use Cbox\Cms\Core\Structure\Domain\Events\NodeRouteChanged;
use Cbox\Cms\Core\Structure\Domain\Events\NodeRouteChangedV1;

// A newsroom gives its site a section for sport: node.create puts the node below the site's root,
// node.set_route gives it the URL /sport in Danish, and node.archive closes it again once nothing
// below it is visible. The ids are the caller's, so a repeat with the same idempotency key is the
// same node.

const NODE_EXAMPLE_SITE = '01936f5e-8a2b-7c3d-9e4f-000000000d01';

const NODE_EXAMPLE_ROOT = '01936f5e-8a2b-7c3d-9e4f-000000000d02';

const NODE_EXAMPLE_SECTION = '01936f5e-8a2b-7c3d-9e4f-000000000d03';

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
