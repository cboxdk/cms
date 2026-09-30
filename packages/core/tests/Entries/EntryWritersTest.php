<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Core\Entries\Adapter\EntryCreatedWriter;
use Cbox\Cms\Core\Entries\Adapter\HeadMovedWriter;
use Cbox\Cms\Core\Entries\Adapter\RevisionCreatedWriter;
use Cbox\Cms\Core\Pipeline\Domain\Dto\MutationContext;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;

/*
 * Each writer of the entry mutations writes its own class of mutation and nothing else, and the
 * revision writer refuses a type the installation does not have, which the pipeline rules out
 * before the commit. Each refuses before it touches the database.
 */

function writerContext(): MutationContext
{
    return new MutationContext(
        ChangesetId::fromString('019cd79e-4600-7000-8000-0000000007c1'),
        new DateTimeImmutable('2026-03-10T12:00:00Z'),
        ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007a1'),
        AggregateVersion::first(),
    );
}

it('names the class of mutation each writer writes, and refuses another', function (): void {
    $connections = app(ConnectionResolverInterface::class);
    $other = new ActorDeactivated(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007a1'));

    foreach ([
        [new EntryCreatedWriter($connections), EntryCreated::class],
        [new RevisionCreatedWriter($connections, new FakeTypeCatalog), RevisionCreated::class],
        [new HeadMovedWriter($connections), HeadMoved::class],
    ] as [$writer, $class]) {
        expect($writer->writes())->toBe($class)
            ->and(fn (): array => $writer->write($other, writerContext()))->toThrow(InvalidArgumentException::class, ActorDeactivated::class);
    }
});

it('refuses a revision of a type the installation does not have', function (): void {
    $writer = new RevisionCreatedWriter(app(ConnectionResolverInterface::class), new FakeTypeCatalog);
    $revision = new RevisionCreated(
        EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007e1'),
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007d1'),
        VariantKey::shared(),
        RevisionNumber::first(),
        new FieldValues,
    );

    expect(fn (): array => $writer->write($revision, writerContext()))->toThrow(LogicException::class, 'No type of this installation has the id 01936f5e-8a2b-7c3d-9e4f-0000000007d1');
});
