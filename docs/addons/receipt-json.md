---
title: Receipt JSON
weight: 46
description: "The JSON form of the receipt a write returns, receipt.v1.json: the outcome, the changeset, its commit position and consistency token, the wait level and the status of each projection, and the generated codec that writes and reads it."
---

# Receipt JSON

<!-- extension-point: packages/contracts/resources/schemas/receipt.v1.json -->

Every write returns a receipt (PRD 6.1, 8.4): how the command ended, the changeset it committed, its commit position and where the commit is in the WAL, the wait level the caller asked for, and the status of each projection the changeset affected. Its PHP form is `Cbox\Cms\Contracts\Receipts\Receipt`, and its JSON form is contract version 1, described by the JSON Schema [`receipt.v1.json`](../../packages/contracts/resources/schemas/receipt.v1.json). A surface that answers with the receipt writes it with the generated codec `Cbox\Cms\Core\Codecs\Boundary\Generated\ReceiptCodecV1`, and a PHP client reads it with the same codec. All of it is `#[Experimental]`.

## The document

Every key is always present, the keys are sorted and there is no whitespace, so one receipt always gives the same bytes. A value that does not apply is `null`.

| Key | JSON | PHP |
|---|---|---|
| `changeset_id` | the changeset, a UUIDv7 in lowercase hex, or `null` when nothing was committed | `?ChangesetId $changesetId` |
| `consistency_token` | the consistency token of the commit (PRD 8.5), an object of `generation` (the Postgres timeline, 1 or more) and `lsn` (the WAL position as Postgres writes a `pg_lsn`, such as `0/16B3748`), or `null` when nothing was committed or the call did not read it | `?ConsistencyToken $consistencyToken` |
| `outcome` | `rejected`, `committed`, `committed_wait_timeout` or `dry_run` | `Outcome $outcome` |
| `position` | the commit position of the changeset (PRD 8.4, 8.12), the xid8 of its transaction in decimal as a string, such as `"4827"`, or `null` when nothing was committed; a read whose snapshot xmin is above it saw the changeset (see [the commit position](contracts/receipt-store.md#the-commit-position)) | `?CommitPosition $position` |
| `projections` | the status of each projection the changeset affected, sorted by projection name: `projection`, `state` (`pending` or `acknowledged`) and `acknowledged_at` (RFC 3339 in UTC with six decimals, or `null` while pending) | `list<ProjectionStatus> $projections` |
| `retention_class` | `evidence` or `standard` | `RetentionClass $retentionClass` |
| `wait_level` | `commit`, `origin`, `edge`, `verified` or `propagated` | `WaitLevel $waitLevel` |

A committed and a `committed_wait_timeout` receipt have a `changeset_id` and a `position`. A `rejected` and a `dry_run` receipt committed nothing, so their `changeset_id`, `position` and `consistency_token` are `null` and they have no projections. The schema cannot say that, so the `Receipt` does, and the codec refuses a document that breaks it with `json_invalid`, as it refuses one that breaks the schema.

## The codec is generated

`composer generate:protocol` reads the schema and writes the codec into `packages/core/src/Codecs/Boundary/Generated`, bound to the classes of the contracts, so no code serialises a receipt by hand (GUARDRAILS 2.2). The codec implements [`JsonCodec`](codecs.md#the-jsoncodec-contract) for `Receipt`. `composer check:generated` fails when the committed codec is not what the schema generates, and the Arch suite fails on code outside a `Generated` directory that serialises a `Receipt` itself. A change of the JSON form is a change of the schema, and one that is not backwards compatible is a new contract version with a schema file and a codec of its own.

`decode()` refuses a document with `Cbox\Cms\Core\Codecs\Domain\DecodingFailed`: `json_malformed` for a document that is not a JSON object, and `json_invalid` with the path of the value for a key that is missing or unknown, a value of the wrong kind, and a receipt the `Receipt` refuses.

The example is in the `Unit` suite:

<!-- example: examples/Unit/Protocol/ReceiptJsonTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\ConsistencyToken;
use Cbox\Cms\Contracts\Consistency\LogSequenceNumber;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Codecs\Boundary\Generated\ReceiptCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;

// A surface answers a write with its receipt, written by the generated codec of receipt.v1.json:
// the edge has not acknowledged yet, so the call waited for origin and got the changeset's commit
// position and the consistency token of its commit.

it('answers a write with the receipt as canonical JSON', function (): void {
    $receipt = Receipt::committed(
        ChangesetId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'),
        WaitLevel::Origin,
        RetentionClass::Standard,
        new CommitPosition('4827'),
        [
            ProjectionStatus::acknowledged(new ProjectionName('fragments'), new DateTimeImmutable('2026-03-10T12:00:00.125Z')),
            ProjectionStatus::pending(new ProjectionName('edge')),
        ],
        new ConsistencyToken(1, new LogSequenceNumber('0/16B3748')),
    );

    expect(new ReceiptCodecV1()->encode($receipt, ClassificationAccess::Public))->toBe(
        '{"changeset_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02",'
        .'"consistency_token":{"generation":1,"lsn":"0/16B3748"},"outcome":"committed","position":"4827",'
        .'"projections":[{"acknowledged_at":null,"projection":"edge","state":"pending"},'
        .'{"acknowledged_at":"2026-03-10T12:00:00.125000Z","projection":"fragments","state":"acknowledged"}],'
        .'"retention_class":"standard","wait_level":"origin"}',
    );
});

it('reads a receipt a client kept, and refuses one that breaks the contract', function (): void {
    $codec = new ReceiptCodecV1;
    $receipt = $codec->decode(
        '{"changeset_id":null,"consistency_token":null,"outcome":"rejected","position":null,"projections":[],"retention_class":"standard","wait_level":"commit"}',
        ClassificationAccess::Public,
    );

    expect($receipt->outcome)->toBe(Outcome::Rejected)
        ->and($receipt->isCommitted())->toBeFalse()
        ->and(static fn (): Receipt => $codec->decode(
            '{"changeset_id":null,"consistency_token":null,"outcome":"committed","position":"4827","projections":[],"retention_class":"standard","wait_level":"commit"}',
            ClassificationAccess::Public,
        ))->toThrow(DecodingFailed::class, '[json_invalid] breaks a rule of the contract: A committed receipt needs a ChangesetId: the command committed a changeset.');
});
```
