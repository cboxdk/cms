<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Core\Entries\Actions\CreateEntryAction;
use Cbox\Cms\Core\Entries\Actions\ReviseEntryAction;
use Cbox\Cms\Core\Entries\Adapter\EntryCreatedWriter;
use Cbox\Cms\Core\Entries\Adapter\HeadMovedWriter;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryReader;
use Cbox\Cms\Core\Entries\Adapter\PostgresEntryVersionLock;
use Cbox\Cms\Core\Entries\Adapter\PostgresVariantVersionLock;
use Cbox\Cms\Core\Entries\Adapter\RevisionCreatedWriter;
use Cbox\Cms\Core\Entries\Domain\Commands\CreateEntry;
use Cbox\Cms\Core\Entries\Domain\Commands\ReviseEntry;
use Cbox\Cms\Core\Entries\Domain\EntryReader;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\Pipeline\Domain\WriteActions;
use Cbox\Cms\Core\Registry\Domain\Dto\CompiledRegistry;
use Cbox\Cms\Core\Structure\Adapter\PostgresNodeVersionLock;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;

/*
 * The entry commands are wired into the kernel (PRD 6.2, 13.2): cms:build registers their actions
 * under entry.create and entry.revise version 1 on every surface, the container gives the pipeline
 * those actions with the Postgres reader, and the commit finds the lock of every aggregate they
 * read and the writer of every mutation they plan. Each lock refuses an aggregate of another kind.
 */

const REGISTRATION_ENTRY = '01936f5e-8a2b-7c3d-9e4f-0000000004e1';
const REGISTRATION_NODE = '01936f5e-8a2b-7c3d-9e4f-0000000004a1';
const REGISTRATION_TYPE = '01936f5e-8a2b-7c3d-9e4f-0000000004d1';

it('registers entry.create and entry.revise version 1 on every surface', function (): void {
    $registry = app(CompiledRegistry::class);

    foreach ([CreateEntry::class => [CreateEntryAction::class, 'entry.create'], ReviseEntry::class => [ReviseEntryAction::class, 'entry.revise']] as $command => [$action, $name]) {
        $entry = $registry->actionFor($command);

        expect($entry?->class)->toBe($action)
            ->and($entry?->command->value)->toBe($name)
            ->and($entry?->commandVersion)->toBe(1)
            ->and($entry?->package)->toBe('cboxdk/cms')
            ->and($entry?->surfaces)->toBe([Surface::Rest, Surface::Inertia, Surface::Mcp, Surface::Cli]);
    }
});

it('gives the pipeline the entry actions with the Postgres reader', function (): void {
    $entry = EntryId::fromString(REGISTRATION_ENTRY);
    $create = app(WriteActions::class)->for(new CreateEntry($entry, TypeId::fromString(REGISTRATION_TYPE), NodeId::fromString(REGISTRATION_NODE), new FieldValues));
    $revise = app(WriteActions::class)->for(new ReviseEntry($entry, new AggregateVersion(1), new FieldValues));

    expect($create->action)->toBeInstanceOf(CreateEntryAction::class)
        ->and($create->command->value)->toBe('entry.create')
        ->and($revise->action)->toBeInstanceOf(ReviseEntryAction::class)
        ->and(app(EntryReader::class))->toBeInstanceOf(PostgresEntryReader::class);
});

it('gives the commit the locks of entries, variants and nodes and the writers of the entry mutations', function (): void {
    $entry = EntryId::fromString(REGISTRATION_ENTRY);
    $type = TypeId::fromString(REGISTRATION_TYPE);
    $shared = VariantKey::shared();
    $locks = app(VersionLocks::class);
    $writers = app(MutationWriters::class);

    expect($locks->for($entry))->toBeInstanceOf(PostgresEntryVersionLock::class)
        ->and($locks->for(new VariantRef($entry, $shared)))->toBeInstanceOf(PostgresVariantVersionLock::class)
        ->and($locks->for(NodeId::fromString(REGISTRATION_NODE)))->toBeInstanceOf(PostgresNodeVersionLock::class)
        ->and($writers->for(new EntryCreated($entry, $type, NodeId::fromString(REGISTRATION_NODE))))->toBeInstanceOf(EntryCreatedWriter::class)
        ->and($writers->for(new RevisionCreated($entry, $type, $shared, RevisionNumber::first(), new FieldValues)))->toBeInstanceOf(RevisionCreatedWriter::class)
        ->and($writers->for(new HeadMoved($entry, $shared, null, RevisionNumber::first())))->toBeInstanceOf(HeadMovedWriter::class);
});

it('refuses to lock an aggregate of another kind', function (string $kind): void {
    $connections = app(ConnectionResolverInterface::class);
    [$lock, $other] = match ($kind) {
        'entry' => [new PostgresEntryVersionLock($connections), ActorId::fromString(REGISTRATION_ENTRY)],
        'variant' => [new PostgresVariantVersionLock($connections), EntryId::fromString(REGISTRATION_ENTRY)],
        default => [new PostgresNodeVersionLock($connections), EntryId::fromString(REGISTRATION_ENTRY)],
    };

    expect($lock->kind())->toBe($kind)
        ->and(fn (): ?AggregateVersion => $lock->lock($other, LockStrength::Share))->toThrow(InvalidArgumentException::class, $other->aggregateKey());
})->with(['entry', 'variant', 'node']);
