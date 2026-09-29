<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Results;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\AggregateChange;
use Cbox\Cms\Contracts\Results\AggregateCount;
use Cbox\Cms\Contracts\Results\BlastRadius;
use Cbox\Cms\Contracts\Results\DryRunReport;
use Cbox\Cms\Contracts\Results\InvalidWriteResult;

/*
 * The report of a dry run (PRD 6.1, 6.2 phase 6): the plan, its blast radius as counts by kind of
 * aggregate, and its diff, one change per aggregate with the version read and the version the
 * commit would give it.
 */

function reportUuid(int $n): string
{
    return sprintf('01936f5e-8a2b-7c3d-9e4f-%012d', $n);
}

it('counts the mutations and the aggregates by kind, and diffs each aggregate once', function (): void {
    $entry = EntryId::fromString(reportUuid(1));
    $type = TypeId::fromString(reportUuid(2));
    $shared = VariantKey::shared();
    $danish = VariantKey::of(new Locale('da'));
    $actor = ActorId::fromString(reportUuid(3));
    $plan = new Plan(
        new EntryCreated($entry, $type, NodeId::fromString(reportUuid(4))),
        new RevisionCreated($entry, $type, $shared, RevisionNumber::first(), new FieldValues),
        new HeadMoved($entry, $shared, null, RevisionNumber::first()),
    )->then(new Plan(new RevisionCreated($entry, $type, $danish, new RevisionNumber(3), new FieldValues)), new Plan(new ActorDeactivated($actor)));
    $reads = new ReadVersions(
        ReadVersion::absent($entry),
        ReadVersion::absent(new VariantRef($entry, $shared)),
        ReadVersion::at(new VariantRef($entry, $danish), new AggregateVersion(7)),
        ReadVersion::at($actor, new AggregateVersion(2)),
        ReadVersion::at(ActorId::fromString(reportUuid(9)), AggregateVersion::first()),
    );

    $report = DryRunReport::of($plan, $reads);

    expect($report->plan)->toBe($plan)
        ->and($report->blastRadius->mutations)->toBe(5)
        ->and($report->blastRadius->total())->toBe(4)
        ->and(array_map(static fn (AggregateCount $count): string => $count->kind.'='.$count->count, $report->blastRadius->aggregates))->toBe(['actor=1', 'entry=1', 'variant=2'])
        ->and($report->blastRadius->of('placement'))->toBe(0)
        ->and(array_map(
            static fn (AggregateChange $change): string => sprintf('%s %s->%d x%d%s', $change->aggregate->aggregateKey(), $change->before->value ?? 'new', $change->after->value, $change->mutations, $change->creates() ? ' created' : ''),
            $report->diff,
        ))->toBe([
            'actor:'.reportUuid(3).' 2->3 x1',
            'entry:'.reportUuid(1).' new->1 x1 created',
            'variant:'.reportUuid(1).':da 7->8 x1',
            'variant:'.reportUuid(1).':shared new->1 x2 created',
        ]);
});

it('reports an empty plan as nothing reached', function (): void {
    $report = DryRunReport::of(Plan::empty(), new ReadVersions(ReadVersion::absent(EntryId::fromString(reportUuid(1)))));

    expect($report->blastRadius->mutations)->toBe(0)
        ->and($report->blastRadius->total())->toBe(0)
        ->and($report->blastRadius->aggregates)->toBe([])
        ->and($report->diff)->toBe([]);
});

it('refuses a plan that changes an aggregate that was not read', function (): void {
    $actor = ActorId::fromString(reportUuid(3));

    expect(static fn (): DryRunReport => DryRunReport::of(new Plan(new ActorDeactivated($actor)), new ReadVersions))
        ->toThrow(InvalidWriteResult::class, 'A mutation changes the aggregate "actor:'.reportUuid(3).'", which the write did not read.');
});

it('holds the counts and changes to their rules', function (): void {
    $entry = EntryId::fromString(reportUuid(1));

    expect(static fn (): AggregateChange => new AggregateChange($entry, null, 0))->toThrow(InvalidWriteResult::class, 'A change of the aggregate "entry:'.reportUuid(1).'" counts at least one mutation.')
        ->and(static fn (): AggregateCount => new AggregateCount('entry', 0))->toThrow(InvalidWriteResult::class, 'got 0 for "entry"')
        ->and(static fn (): AggregateCount => new AggregateCount('', 1))->toThrow(InvalidWriteResult::class, 'got 1 for ""')
        ->and(static fn (): BlastRadius => new BlastRadius(-1, []))->toThrow(InvalidWriteResult::class, 'got -1 for "mutation"')
        ->and(static fn (): BlastRadius => new BlastRadius(2, [new AggregateCount('entry', 1), new AggregateCount('entry', 2)]))->toThrow(InvalidWriteResult::class, 'A blast radius counts the aggregate kind "entry" once.')
        ->and(new BlastRadius(0, [new AggregateCount('variant', 2), new AggregateCount('actor', 1)])->aggregates[0]->kind)->toBe('actor')
        ->and(new AggregateCount('entry', 1)->count)->toBe(1);
});
