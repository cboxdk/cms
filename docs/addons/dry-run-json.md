---
title: Dry run JSON
weight: 46
description: "The JSON form of what a dry run reports, dry-run-summary.v1.json: the blast radius, the version change of each aggregate the commit would make and what becomes visible, and the generated codec that writes and reads it."
---

# Dry run JSON

<!-- extension-point: packages/contracts/resources/schemas/dry-run-summary.v1.json -->

A write with `dry_run` in its envelope runs every phase up to the commit and commits nothing (PRD 6.1, 6.2 phase 6). The kernel's result holds the `DryRunReport`: the plan with its typed mutations, the blast radius and the diff. A surface shows the caller the report without the plan, as `Cbox\Cms\Contracts\Results\DryRunSummary`, built with `DryRunSummary::of($report)`: the blast radius, one `VersionChange` per aggregate the commit would make, sorted by aggregate key, and every placement the write makes visible (`BecomesVisible`). Its JSON form is contract version 1, described by the JSON Schema [`dry-run-summary.v1.json`](../../packages/contracts/resources/schemas/dry-run-summary.v1.json), and written and read only by the generated codec `Cbox\Cms\Core\Codecs\Boundary\Generated\DryRunSummaryCodecV1`. All of it is `#[Experimental]`.

The Inertia profile flashes the summary beside the receipt of a dry run, under `dry_run`, and the panel's host hands it to a contribution as the `dryRun` member of a command's answer, so an action that asks for a dry run shows the viewer what would change before the command runs for real ([panel shell points](panel/shell.md#actions)). REST, MCP and the CLI answer a dry run with the receipt alone.

## The document

Every key is always present, the keys are sorted and there is no whitespace, so one summary always gives the same bytes.

| Key | JSON | PHP |
|---|---|---|
| `blast_radius` | `mutations`, the number of mutations in the plan, and `aggregates`, the number of distinct aggregates they change by `kind`, each kind once, sorted by kind | `BlastRadius $blastRadius` |
| `changes` | one object per aggregate the commit would change, sorted by `aggregate`, its key `<kind>:<id>` as the kernel writes it: `before`, the version read, or `null` for an aggregate the write creates; `after`, the version the commit would give it, one higher or `1`; and `mutations`, how many of the plan's mutations change it | `list<VersionChange> $changes` |
| `becomes_visible` | each placement and locale the write makes visible to the public, sorted by placement and locale: `placement`, `locale` and `from`, RFC 3339 in UTC with six decimals | `list<BecomesVisible> $becomesVisible` |

The schema cannot say that `after` follows `before`, or that an aggregate appears once; the `VersionChange` and the `DryRunSummary` do, and the codec refuses a document that breaks it with `json_invalid`, as it refuses one that breaks the schema.

## The codec is generated

`composer generate:protocol` reads the schema and writes the codec into `packages/core/src/Codecs/Boundary/Generated`, bound to the classes of the contracts, so no code serialises a summary by hand (GUARDRAILS 2.2); it writes the TypeScript type and validator `DryRunSummaryV1` for the panel and the panel SDK too. `composer check:generated` fails when the committed codec is not what the schema generates.

The example is in the `Unit` suite:

<!-- example: examples/Unit/Protocol/DryRunJsonTest.php -->
```php
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
```
