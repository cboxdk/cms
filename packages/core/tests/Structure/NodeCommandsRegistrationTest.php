<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Structure;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\NodeArchived;
use Cbox\Cms\Contracts\Plans\Mutations\NodeCreated;
use Cbox\Cms\Contracts\Plans\Mutations\NodeRouteSet;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Structure\Actions\ArchiveNodeAction;
use Cbox\Cms\Core\Structure\Actions\CreateNodeAction;
use Cbox\Cms\Core\Structure\Actions\SetNodeRouteAction;
use Cbox\Cms\Core\Structure\Adapter\NodeArchivedWriter;
use Cbox\Cms\Core\Structure\Adapter\NodeCreatedWriter;
use Cbox\Cms\Core\Structure\Adapter\NodeRouteSetWriter;
use Cbox\Cms\Core\Structure\Adapter\PostgresNodeReader;
use Cbox\Cms\Core\Structure\Adapter\PostgresNodeRouteLock;
use Cbox\Cms\Core\Structure\Domain\Commands\ArchiveNode;
use Cbox\Cms\Core\Structure\Domain\Commands\CreateNode;
use Cbox\Cms\Core\Structure\Domain\Commands\SetNodeRoute;
use Cbox\Cms\Core\Structure\Domain\NodeReader;
use Cbox\Cms\Core\Structure\Domain\NodeRouteRef;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

/*
 * The node commands are wired into the kernel (PRD 5.8, 5.9, 6.2, 13.2): cms:build registers their
 * actions under node.create, node.archive and node.set_route version 1 on every surface, the
 * container gives the pipeline those actions with the Postgres reader, and the commit finds the
 * lock of every aggregate they read and the writer of every mutation they plan. The route lock
 * refuses an aggregate of another kind.
 */

const NODE_REGISTRATION_NODE = '01936f5e-8a2b-7c3d-9e4f-000000000c01';

const NODE_REGISTRATION_PARENT = '01936f5e-8a2b-7c3d-9e4f-000000000c02';

const NODE_REGISTRATION_SITE = '01936f5e-8a2b-7c3d-9e4f-000000000c11';

it('registers node.create, node.archive and node.set_route version 1 on every surface', function (): void {
    $registry = app(CompiledRegistry::class);

    foreach ([
        CreateNode::class => [CreateNodeAction::class, 'node.create'],
        ArchiveNode::class => [ArchiveNodeAction::class, 'node.archive'],
        SetNodeRoute::class => [SetNodeRouteAction::class, 'node.set_route'],
    ] as $command => [$action, $name]) {
        $entry = $registry->actionFor($command);

        expect($entry?->class)->toBe($action)
            ->and($entry?->command->value)->toBe($name)
            ->and($entry?->commandVersion)->toBe(1)
            ->and($entry?->package)->toBe('cboxdk/cms')
            ->and($entry?->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]);
    }
});

it('gives the pipeline the node actions with the Postgres reader', function (): void {
    $node = NodeId::fromString(NODE_REGISTRATION_NODE);
    $create = app(WriteActions::class)->for(new CreateNode($node, NodeId::fromString(NODE_REGISTRATION_PARENT), NodeKind::Section));
    $archive = app(WriteActions::class)->for(new ArchiveNode($node, AggregateVersion::first()));
    $route = app(WriteActions::class)->for(new SetNodeRoute($node, AggregateVersion::first(), SiteId::fromString(NODE_REGISTRATION_SITE), new Locale('da'), new RequestPath('/sport')));

    expect($create->action)->toBeInstanceOf(CreateNodeAction::class)
        ->and($archive->action)->toBeInstanceOf(ArchiveNodeAction::class)
        ->and($route->action)->toBeInstanceOf(SetNodeRouteAction::class)
        ->and($route->command->value)->toBe('node.set_route')
        ->and(app(NodeReader::class))->toBeInstanceOf(PostgresNodeReader::class);
});

it('gives the commit the lock of a route and the writers of the node mutations', function (): void {
    $node = NodeId::fromString(NODE_REGISTRATION_NODE);
    $parent = NodeId::fromString(NODE_REGISTRATION_PARENT);
    $route = new NodeRouteRef(SiteId::fromString(NODE_REGISTRATION_SITE), new Locale('da'), new RequestPath('/sport'));
    $writers = app(MutationWriters::class);
    $label = static fn (NodeId $id): string => str_replace('-', '', $id->toString());

    expect(app(VersionLocks::class)->for($route))->toBeInstanceOf(PostgresNodeRouteLock::class)
        ->and($writers->for(new NodeCreated($node, $parent, 'section', new NodePath($label($parent).'.'.$label($node)))))->toBeInstanceOf(NodeCreatedWriter::class)
        ->and($writers->for(new NodeArchived($node)))->toBeInstanceOf(NodeArchivedWriter::class)
        ->and($writers->for(new NodeRouteSet($node, SiteId::fromString(NODE_REGISTRATION_SITE), new Locale('da'), '/sport')))->toBeInstanceOf(NodeRouteSetWriter::class);
});

it('refuses to lock an aggregate that is not a route of a node', function (): void {
    $lock = new PostgresNodeRouteLock(app(ConnectionResolverInterface::class));
    $other = ActorId::fromString(NODE_REGISTRATION_NODE);

    expect($lock->kind())->toBe('node_route')
        ->and(fn (): ?AggregateVersion => $lock->lock($other, LockStrength::Share))->toThrow(InvalidArgumentException::class, $other->aggregateKey());
});

it('names the class of mutation each node writer writes, and refuses another', function (): void {
    $connections = app(ConnectionResolverInterface::class);
    $other = new NodeArchived(NodeId::fromString(NODE_REGISTRATION_PARENT));

    foreach ([
        [new NodeCreatedWriter($connections), NodeCreated::class],
        [new NodeRouteSetWriter($connections), NodeRouteSet::class],
    ] as [$writer, $class]) {
        expect($writer->writes())->toBe($class)
            ->and(fn (): array => $writer->write($other, nodeWriterContext()))->toThrow(InvalidArgumentException::class, NodeArchived::class);
    }

    $archive = new NodeArchivedWriter($connections);

    expect($archive->writes())->toBe(NodeArchived::class)
        ->and(fn (): array => $archive->write(new NodeRouteSet(NodeId::fromString(NODE_REGISTRATION_NODE), SiteId::fromString(NODE_REGISTRATION_SITE), new Locale('da'), '/sport'), nodeWriterContext()))
        ->toThrow(InvalidArgumentException::class, NodeRouteSet::class);
});

it('refuses a mutation whose kind or path the tree does not take', function (): void {
    $node = NodeId::fromString(NODE_REGISTRATION_NODE);
    $parent = NodeId::fromString(NODE_REGISTRATION_PARENT);
    $label = static fn (NodeId $id): string => str_replace('-', '', $id->toString());

    expect(fn (): NodeCreated => new NodeCreated($node, $parent, 'site', new NodePath($label($parent).'.'.$label($node))))
        ->toThrow(InvalidArgumentException::class, 'a node below another is one of section, page, list, storage')
        ->and(fn (): NodeCreated => new NodeCreated($node, $parent, 'section', new NodePath($label($node))))
        ->toThrow(InvalidArgumentException::class, 'is its parent\'s with the label')
        ->and(fn (): NodeCreated => new NodeCreated($node, $parent, 'section', new NodePath($label($parent).'.'.$label($parent))))
        ->toThrow(InvalidArgumentException::class, 'is its parent\'s with the label')
        ->and(fn (): NodeRouteSet => new NodeRouteSet($node, SiteId::fromString(NODE_REGISTRATION_SITE), new Locale('da'), 'sport'))
        ->toThrow(InvalidArgumentException::class, 'a route is "/" or "/"-separated segments');
});

/**
 * The context a writer gets in the commit: a changeset of the node's actor at version 1.
 */
function nodeWriterContext(): MutationContext
{
    return new MutationContext(
        ChangesetId::fromString('019cd79e-4600-7000-8000-0000000008c1'),
        new DateTimeImmutable('2026-03-10T12:00:00Z'),
        ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000c21'),
        AggregateVersion::first(),
    );
}
