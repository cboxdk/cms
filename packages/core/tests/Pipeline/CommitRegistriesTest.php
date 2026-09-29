<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Core\Identity\Adapter\PostgresActorVersionLock;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriter;
use Cbox\Cms\Core\Pipeline\Domain\MutationWriters;
use Cbox\Cms\Core\Pipeline\Domain\UncommittableChangeset;
use Cbox\Cms\Core\Pipeline\Domain\VersionLock;
use Cbox\Cms\Core\Pipeline\Domain\VersionLocks;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyAdded;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyId;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyVersionLock;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyWorld;
use Cbox\Cms\Core\Tests\Pipeline\Tally\TallyWriter;
use Illuminate\Database\ConnectionResolverInterface;

/*
 * The registries the commit dispatches through (PRD 6.2 phase 7): one MutationWriter per mutation
 * class and one VersionLock per kind of aggregate, from the container's tags.
 */

const COMMIT_ACTOR = '01936f5e-8a2b-7c3d-9e4f-0000000000c1';

it('gives the writer of a mutation\'s class and refuses a mutation without one', function (): void {
    $writer = new TallyWriter;
    $writers = new MutationWriters($writer);

    expect($writers->for(new TallyAdded(TallyWorld::tally(), 1)))->toBe($writer)
        ->and(fn (): MutationWriter => $writers->for(new ActorDeactivated(ActorId::fromString(COMMIT_ACTOR))))
        ->toThrow(UncommittableChangeset::class, sprintf('No MutationWriter is registered for the mutation %s, so the kernel cannot write it. Register the writer of its command with the tag %s.', ActorDeactivated::class, MutationWriters::TAG));
});

it('refuses two writers of one mutation class', function (): void {
    expect(fn (): MutationWriters => new MutationWriters(new TallyWriter, new TallyWriter))
        ->toThrow(UncommittableChangeset::class, sprintf('Two MutationWriters are registered for the mutation %s.', TallyAdded::class));
});

it('gives the lock of an aggregate\'s kind and refuses a kind without one', function (): void {
    $tally = new TallyVersionLock;
    $locks = new VersionLocks($tally);

    expect($locks->for(TallyId::fromString(TallyWorld::TALLY)))->toBe($tally)
        ->and(VersionLocks::kindOf(ActorId::fromString(COMMIT_ACTOR)))->toBe('actor')
        ->and(fn (): VersionLock => $locks->for(ActorId::fromString(COMMIT_ACTOR)))
        ->toThrow(UncommittableChangeset::class, sprintf('No VersionLock is registered for the kind of the aggregate "actor:%s"', COMMIT_ACTOR));
});

it('refuses two locks of one kind', function (): void {
    expect(fn (): VersionLocks => new VersionLocks(new TallyVersionLock, new TallyVersionLock))
        ->toThrow(UncommittableChangeset::class, 'Two VersionLocks are registered for the kind of aggregate "tally".');
});

it('registers the actor\'s version lock in the container and no mutation writer of its own', function (): void {
    $locks = app(VersionLocks::class);

    expect($locks->for(ActorId::fromString(COMMIT_ACTOR)))->toBeInstanceOf(PostgresActorVersionLock::class)
        ->and(fn () => app(MutationWriters::class)->for(new ActorDeactivated(ActorId::fromString(COMMIT_ACTOR))))
        ->toThrow(UncommittableChangeset::class);
});

it('adds a writer and a lock a command tags', function (): void {
    app()->bind(TallyWriter::class, static fn (): TallyWriter => new TallyWriter);
    app()->tag([TallyWriter::class], MutationWriters::TAG);
    app()->tag([TallyVersionLock::class], VersionLocks::TAG);

    expect(app(MutationWriters::class)->for(new TallyAdded(TallyWorld::tally(), 1)))->toBeInstanceOf(TallyWriter::class)
        ->and(app(VersionLocks::class)->for(TallyWorld::tally()))->toBeInstanceOf(TallyVersionLock::class)
        ->and(app(ConnectionResolverInterface::class))->toBeInstanceOf(ConnectionResolverInterface::class);
});
