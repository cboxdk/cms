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
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Contracts\Plans\Mutations\EntryCreated;
use Cbox\Cms\Contracts\Plans\Mutations\HeadMoved;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\BecomesVisible;
use Cbox\Cms\Contracts\Results\BlastRadius;
use Cbox\Cms\Contracts\Results\DryRunReport;
use Cbox\Cms\Contracts\Results\DryRunSummary;
use Cbox\Cms\Contracts\Results\InvalidWriteResult;
use Cbox\Cms\Contracts\Results\VersionChange;
use DateTimeImmutable;

/*
 * The summary of a dry run (PRD 6.1, 6.2 phase 6): the report as a surface shows it, without the
 * plan: the blast radius, each aggregate's version change by key, sorted, and what becomes
 * visible. A change is held to the rule of versions, and a summary names each aggregate once.
 */

function summaryUuid(int $n): string
{
    return sprintf('01936f5e-8a2b-7c3d-9e4f-%012d', $n);
}

it('summarises a report: the blast radius, the version change of each aggregate by key, and what becomes visible', function (): void {
    $entry = EntryId::fromString(summaryUuid(1));
    $type = TypeId::fromString(summaryUuid(2));
    $shared = VariantKey::shared();
    $actor = ActorId::fromString(summaryUuid(3));
    $plan = new Plan(
        new EntryCreated($entry, $type, NodeId::fromString(summaryUuid(4))),
        new RevisionCreated($entry, $type, $shared, RevisionNumber::first(), new FieldValues),
        new HeadMoved($entry, $shared, null, RevisionNumber::first()),
        new ActorDeactivated($actor),
    );
    $reads = new ReadVersions(
        ReadVersion::absent($entry),
        ReadVersion::absent(new VariantRef($entry, $shared)),
        ReadVersion::at($actor, new AggregateVersion(2)),
    );
    $visible = new BecomesVisible(PlacementId::fromString(summaryUuid(5)), new Locale('da'), new DateTimeImmutable('2026-03-10T12:00:00+01:00'));

    $summary = DryRunSummary::of(DryRunReport::of($plan, $reads, [$visible]));

    expect($summary->blastRadius->mutations)->toBe(4)
        ->and($summary->blastRadius->total())->toBe(3)
        ->and(array_map(
            static fn (VersionChange $change): string => sprintf('%s %s->%d x%d%s', $change->aggregate, $change->before->value ?? 'new', $change->after->value, $change->mutations, $change->creates() ? ' created' : ''),
            $summary->changes,
        ))->toBe([
            'actor:'.summaryUuid(3).' 2->3 x1',
            'entry:'.summaryUuid(1).' new->1 x1 created',
            'variant:'.summaryUuid(1).':shared new->1 x2 created',
        ])
        ->and($summary->becomesVisible)->toBe([$visible]);
});

it('sorts the changes by aggregate key, and holds a change to the rule of versions', function (): void {
    $summary = new DryRunSummary(new BlastRadius(2, []), [
        new VersionChange('variant:'.summaryUuid(1).':da', new AggregateVersion(7), new AggregateVersion(8), 1),
        new VersionChange('entry:'.summaryUuid(1), null, AggregateVersion::first(), 1),
    ], []);

    expect(array_map(static fn (VersionChange $change): string => $change->aggregate, $summary->changes))->toBe(['entry:'.summaryUuid(1), 'variant:'.summaryUuid(1).':da'])
        ->and(static fn (): VersionChange => new VersionChange('entry:'.summaryUuid(1), null, new AggregateVersion(2), 1))->toThrow(InvalidWriteResult::class, 'the first for one the write creates')
        ->and(static fn (): VersionChange => new VersionChange('entry:'.summaryUuid(1), new AggregateVersion(2), new AggregateVersion(2), 1))->toThrow(InvalidWriteResult::class, 'one higher')
        ->and(static fn (): VersionChange => new VersionChange('entry:'.summaryUuid(1), null, AggregateVersion::first(), 0))->toThrow(InvalidWriteResult::class, 'counts at least one mutation')
        ->and(static fn (): VersionChange => new VersionChange('entry', null, AggregateVersion::first(), 1))->toThrow(InvalidWriteResult::class, 'names the aggregate\'s key')
        ->and(static fn (): DryRunSummary => new DryRunSummary(new BlastRadius(0, []), [
            new VersionChange('entry:'.summaryUuid(1), null, AggregateVersion::first(), 1),
            new VersionChange('entry:'.summaryUuid(1), null, AggregateVersion::first(), 1),
        ], []))->toThrow(InvalidWriteResult::class, 'names the aggregate "entry:'.summaryUuid(1).'" once');
});
