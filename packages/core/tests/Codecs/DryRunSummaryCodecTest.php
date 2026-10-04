<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

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
use DateTimeImmutable;

/*
 * The summary of a dry run, dry-run-summary.v1.json, through its generated codec (GUARDRAILS 2.2,
 * PRD 6.1): a DryRunSummary round-trips to equal values, its JSON is canonical and validates
 * against the schema with an independent validator, and the codec refuses what the schema or the
 * summary refuses.
 */

function dryRunSummaryCodec(): DryRunSummaryCodecV1
{
    return new DryRunSummaryCodecV1;
}

function dryRunSummary(): DryRunSummary
{
    return new DryRunSummary(
        new BlastRadius(3, [new AggregateCount('variant', 1), new AggregateCount('entry', 1)]),
        [
            new VersionChange('variant:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01:shared', new AggregateVersion(4), new AggregateVersion(5), 2),
            new VersionChange('entry:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01', null, AggregateVersion::first(), 1),
        ],
        [new BecomesVisible(PlacementId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'), new Locale('da'), new DateTimeImmutable('2026-03-10T13:00:00.25+01:00'))],
    );
}

const DRY_RUN_SUMMARY = '{"becomes_visible":[{"from":"2026-03-10T12:00:00.250000Z","locale":"da","placement":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"}],"blast_radius":{"aggregates":[{"count":1,"kind":"entry"},{"count":1,"kind":"variant"}],"mutations":3},"changes":[{"after":1,"aggregate":"entry:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","before":null,"mutations":1},{"after":5,"aggregate":"variant:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01:shared","before":4,"mutations":2}]}';

it('writes a summary as canonical JSON that validates against dry-run-summary.v1.json, and reads it back equal', function (): void {
    $json = dryRunSummaryCodec()->encode(dryRunSummary(), ClassificationAccess::Public);
    $decoded = dryRunSummaryCodec()->decode($json, ClassificationAccess::Public);

    expect($json)->toBe(DRY_RUN_SUMMARY)
        ->and(KernelSchema::errors('dry-run-summary.v1.json', $json))->toBe([])
        ->and($decoded)->toEqual(dryRunSummary())
        ->and(dryRunSummaryCodec()->encode($decoded, ClassificationAccess::Public))->toBe($json)
        ->and(DryRunSummaryCodecV1::VERSION)->toBe(1);
});

it('refuses a document that breaks dry-run-summary.v1.json or the summary, as the schema does', function (string $json, ?string $path, string $reason): void {
    expect(Failures::described(static fn (): DryRunSummary => dryRunSummaryCodec()->decode($json, ClassificationAccess::Public)))->toBe(['json_invalid', $path, $reason])
        ->and(KernelSchema::errors('dry-run-summary.v1.json', $json))->not->toBe([]);
})->with([
    'no changes' => ['{"becomes_visible":[],"blast_radius":{"aggregates":[],"mutations":0}}', 'changes', 'is missing, and the field is required'],
    'a count of 0' => ['{"becomes_visible":[],"blast_radius":{"aggregates":[{"count":0,"kind":"entry"}],"mutations":1},"changes":[]}', 'blast_radius.aggregates[0].count', 'is 0, less than the minimum 1'],
    'a version of 0' => ['{"becomes_visible":[],"blast_radius":{"aggregates":[],"mutations":1},"changes":[{"after":0,"aggregate":"entry:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","before":null,"mutations":1}]}', 'changes[0].after', 'is 0, less than the minimum 1'],
]);

it('refuses a change the summary refuses, which the schema cannot say', function (): void {
    expect(Failures::described(static fn (): DryRunSummary => dryRunSummaryCodec()->decode('{"becomes_visible":[],"blast_radius":{"aggregates":[],"mutations":1},"changes":[{"after":3,"aggregate":"entry:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","before":null,"mutations":1}]}', ClassificationAccess::Public)))
        ->toBe(['json_invalid', 'changes[0]', 'breaks a rule of the contract: A change of the aggregate "entry:0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01" gives it the version after the one read: one higher, or the first for one the write creates.']);
});
