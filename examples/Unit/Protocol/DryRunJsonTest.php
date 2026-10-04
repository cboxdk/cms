<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\AggregateCount;
use Cbox\Cms\Contracts\Results\BecomesVisible;
use Cbox\Cms\Contracts\Results\BlastRadius;
use Cbox\Cms\Contracts\Results\DryRunSummary;
use Cbox\Cms\Contracts\Results\VersionChange;
use Cbox\Cms\Core\Codecs\Boundary\Generated\DryRunSummaryCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;

// A surface answers a dry run with its summary, written by the generated codec of
// dry-run-summary.v1.json: the plan would have released one variant and shown one placement in
// Danish from the time the command read at, and nothing was saved.

it('answers a dry run with its summary as canonical JSON', function (): void {
    $summary = new DryRunSummary(
        new BlastRadius(2, [new AggregateCount('variant', 1), new AggregateCount('placement', 1)]),
        [
            new VersionChange('variant:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01:shared', new AggregateVersion(3), new AggregateVersion(4), 1),
            new VersionChange('placement:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02', new AggregateVersion(1), new AggregateVersion(2), 1),
        ],
        [new BecomesVisible(PlacementId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'), new Locale('da'), new DateTimeImmutable('2026-03-10T12:00:00Z'))],
    );

    expect(new DryRunSummaryCodecV1()->encode($summary, ClassificationAccess::Public))->toBe(
        '{"becomes_visible":[{"from":"2026-03-10T12:00:00.000000Z","locale":"da","placement":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"}],'
        .'"blast_radius":{"aggregates":[{"count":1,"kind":"placement"},{"count":1,"kind":"variant"}],"mutations":2},'
        .'"changes":[{"after":2,"aggregate":"placement:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","before":1,"mutations":1},'
        .'{"after":4,"aggregate":"variant:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01:shared","before":3,"mutations":1}]}',
    );
});

it('reads a summary a client kept, and refuses one that breaks the contract', function (): void {
    $codec = new DryRunSummaryCodecV1;
    $summary = $codec->decode(
        '{"becomes_visible":[],"blast_radius":{"aggregates":[{"count":1,"kind":"entry"}],"mutations":1},"changes":[{"after":1,"aggregate":"entry:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","before":null,"mutations":1}]}',
        ClassificationAccess::Public,
    );

    expect($summary->blastRadius->total())->toBe(1)
        ->and($summary->changes[0]->creates())->toBeTrue()
        ->and(static fn (): DryRunSummary => $codec->decode(
            '{"becomes_visible":[],"blast_radius":{"aggregates":[],"mutations":1},"changes":[{"after":5,"aggregate":"entry:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","before":null,"mutations":1}]}',
            ClassificationAccess::Public,
        ))->toThrow(DecodingFailed::class, 'gives it the version after the one read');
});
