<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Changesets;

use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Core\Changesets\Domain\Dto\ChangesetRecord;
use DateTimeImmutable;
use InvalidArgumentException;

/*
 * The record of a changeset lists each aggregate it changed once, the first ref of each key in the
 * order given, as a list, and refuses a changeset that changes nothing.
 */

/**
 * @param  list<AggregateRef>  $aggregates
 */
function changesetRecord(array $aggregates): ChangesetRecord
{
    $actor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000301');

    return new ChangesetRecord(
        ChangesetId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000302'),
        new DateTimeImmutable('2026-10-01T12:00:00Z'),
        RetentionClass::Standard,
        new CommandName('probe.rename'),
        1,
        Envelope::internal(IssuingSurface::Subscriber, IssuerKind::System, $actor, new UnitOfWork('record'), new CorrelationId('record')),
        $aggregates,
    );
}

it('keeps the first ref of each aggregate key, in the order given, as a list', function (): void {
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000303');
    $again = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000303');
    $node = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000304');

    $aggregates = changesetRecord([$entry, $node, $again])->aggregates;

    expect($aggregates)->toBeList()
        ->and($aggregates)->toHaveCount(2)
        ->and($aggregates[0])->toBe($entry)
        ->and($aggregates[1])->toBe($node);
});

it('refuses a changeset that changes no aggregate', function (): void {
    expect(static fn (): ChangesetRecord => changesetRecord([]))->toThrow(InvalidArgumentException::class, 'A changeset changes at least one aggregate.');
});
