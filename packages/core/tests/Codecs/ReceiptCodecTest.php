<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

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
use DateTimeImmutable;

/*
 * The receipt's JSON form, receipt.v1.json, through its generated codec (GUARDRAILS 2.2, PRD 8.4):
 * a Receipt round-trips to equal values, its JSON is canonical and validates against the schema
 * with an independent validator, and the codec refuses what the schema or the Receipt refuses.
 */

function receiptCodec(): ReceiptCodecV1
{
    return new ReceiptCodecV1;
}

function committedReceipt(): Receipt
{
    return Receipt::committed(
        ChangesetId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'),
        WaitLevel::Origin,
        RetentionClass::Standard,
        new CommitPosition('4827'),
        [
            ProjectionStatus::pending(new ProjectionName('search')),
            ProjectionStatus::acknowledged(new ProjectionName('fragments'), new DateTimeImmutable('2026-03-10T13:00:00.25+01:00')),
        ],
        new ConsistencyToken(2, new LogSequenceNumber('16/B374D848')),
    );
}

const COMMITTED_RECEIPT = '{"changeset_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","consistency_token":{"generation":2,"lsn":"16/B374D848"},"outcome":"committed","position":"4827","projections":[{"acknowledged_at":"2026-03-10T12:00:00.250000Z","projection":"fragments","state":"acknowledged"},{"acknowledged_at":null,"projection":"search","state":"pending"}],"retention_class":"standard","wait_level":"origin"}';

it('writes a committed receipt as canonical JSON that validates against receipt.v1.json, and reads it back equal', function (): void {
    $json = receiptCodec()->encode(committedReceipt(), ClassificationAccess::Public);
    $decoded = receiptCodec()->decode($json, ClassificationAccess::Public);

    expect($json)->toBe(COMMITTED_RECEIPT)
        ->and(KernelSchema::errors('receipt.v1.json', $json))->toBe([])
        ->and($decoded)->toEqual(committedReceipt())
        ->and($decoded->consistencyToken?->equals(new ConsistencyToken(2, new LogSequenceNumber('16/B374D848'))))->toBeTrue()
        ->and($decoded->position?->value)->toBe('4827')
        ->and(receiptCodec()->encode($decoded, ClassificationAccess::Public))->toBe($json);
});

it('round-trips every outcome, wait level and retention class, with and without a consistency token', function (Receipt $receipt, string $json): void {
    expect(receiptCodec()->encode($receipt, ClassificationAccess::Public))->toBe($json)
        ->and(KernelSchema::errors('receipt.v1.json', $json))->toBe([])
        ->and(receiptCodec()->decode($json, ClassificationAccess::Public))->toEqual($receipt);
})->with([
    'rejected' => [Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard), '{"changeset_id":null,"consistency_token":null,"outcome":"rejected","position":null,"projections":[],"retention_class":"standard","wait_level":"commit"}'],
    'a dry run' => [Receipt::dryRun(WaitLevel::Edge, RetentionClass::Evidence), '{"changeset_id":null,"consistency_token":null,"outcome":"dry_run","position":null,"projections":[],"retention_class":"evidence","wait_level":"edge"}'],
    'a wait timeout without a consistency token' => [
        Receipt::committedWaitTimeout(ChangesetId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'), WaitLevel::Verified, RetentionClass::Evidence, new CommitPosition('3')),
        '{"changeset_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","consistency_token":null,"outcome":"committed_wait_timeout","position":"3","projections":[],"retention_class":"evidence","wait_level":"verified"}',
    ],
    'propagated with the largest position and token' => [
        Receipt::committed(ChangesetId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02'), WaitLevel::Propagated, RetentionClass::Standard, new CommitPosition(CommitPosition::MAX), [], new ConsistencyToken(ConsistencyToken::MAX_GENERATION, new LogSequenceNumber('FFFFFFFF/FFFFFFFF'))),
        '{"changeset_id":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02","consistency_token":{"generation":4294967295,"lsn":"FFFFFFFF/FFFFFFFF"},"outcome":"committed","position":"18446744073709551615","projections":[],"retention_class":"standard","wait_level":"propagated"}',
    ],
]);

it('reads any order of keys, an upper case id and an offset, and writes them canonically', function (): void {
    $json = '{"wait_level":"origin","retention_class":"standard","projections":[{"state":"pending","projection":"search","acknowledged_at":null},{"state":"acknowledged","projection":"fragments","acknowledged_at":"2026-03-10T13:00:00.25+01:00"}],"position":"4827","consistency_token":{"lsn":"16/B374D848","generation":2},"outcome":"committed","changeset_id":"0199A3C1-2B4D-7E5F-8A6B-1C2D3E4F5A02"}';

    expect(receiptCodec()->encode(receiptCodec()->decode($json, ClassificationAccess::Public), ClassificationAccess::Public))->toBe(COMMITTED_RECEIPT);
});

it('refuses a document that breaks receipt.v1.json, as the schema does', function (string $json, string $code, ?string $path, string $reason): void {
    expect(Failures::described(static fn (): Receipt => receiptCodec()->decode($json, ClassificationAccess::Public)))->toBe([$code, $path, $reason])
        ->and(KernelSchema::errors('receipt.v1.json', $json))->not->toBe([]);
})->with([
    'not an object' => ['[]', 'json_malformed', null, 'the document is not a JSON object'],
    'a missing key' => ['{"changeset_id":null,"consistency_token":null,"outcome":"rejected","projections":[],"retention_class":"standard","wait_level":"commit"}', 'json_invalid', 'position', 'is missing, and the field is required'],
    'an unknown key' => [str_replace('"wait_level"', '"codec_version":1,"wait_level"', COMMITTED_RECEIPT), 'json_invalid', null, 'has the key "codec_version", which is not a field of the contract'],
    'an unknown outcome' => [str_replace('"committed"', '"done"', COMMITTED_RECEIPT), 'json_invalid', 'outcome', 'is not one of rejected, committed, committed_wait_timeout, dry_run'],
    'a null wait level' => [str_replace('"wait_level":"origin"', '"wait_level":null', COMMITTED_RECEIPT), 'json_invalid', 'wait_level', 'is null, and the field is required'],
    'a changeset id that is not a UUIDv7' => [str_replace('7e5f', '4e5f', COMMITTED_RECEIPT), 'json_invalid', 'changeset_id', 'is not a valid id: "0199a3c1-2b4d-4e5f-8a6b-1c2d3e4f5a02" is a UUID version 4, not version 7.'],
    'a generation of 0' => [str_replace('"generation":2', '"generation":0', COMMITTED_RECEIPT), 'json_invalid', 'consistency_token.generation', 'is 0, less than the minimum 1'],
    'a lower case WAL position' => [str_replace('16/B374D848', '16/b374d848', COMMITTED_RECEIPT), 'json_invalid', 'consistency_token.lsn', 'is not valid: A WAL position is two groups of 1 to 8 uppercase hex digits separated by a slash, as Postgres writes a pg_lsn, for example "16/B374D848", got "16/b374d848".'],
    'a position with a leading zero' => [str_replace('"4827"', '"04827"', COMMITTED_RECEIPT), 'json_invalid', 'position', 'is not valid: A commit position is a Postgres xid8 in decimal without leading zeros, 0 to 18446744073709551615, for example "4827", got "04827".'],
    'a position as a number' => [str_replace('"4827"', '4827', COMMITTED_RECEIPT), 'json_invalid', 'position', 'is not a string'],
    'a projection name with a capital' => [str_replace('"search"', '"Search"', COMMITTED_RECEIPT), 'json_invalid', 'projections[1].projection', 'is not valid: A projection name is dot-separated snake_case segments of at most 63 characters, for example "fragments" or "acme.search", got "Search".'],
    'an acknowledgement that is not a time' => [str_replace('2026-03-10T12:00:00.250000Z', 'yesterday', COMMITTED_RECEIPT), 'json_invalid', 'projections[0].acknowledged_at', 'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z'],
    'a list of projections that is an object' => ['{"changeset_id":null,"consistency_token":null,"outcome":"rejected","position":null,"projections":{},"retention_class":"standard","wait_level":"commit"}', 'json_invalid', 'projections', 'is not a list'],
]);

it('refuses a document that the schema allows but the Receipt does not, at the object that breaks it', function (string $json, ?string $path, string $reason): void {
    expect(Failures::described(static fn (): Receipt => receiptCodec()->decode($json, ClassificationAccess::Public)))->toBe(['json_invalid', $path, $reason])
        ->and(KernelSchema::errors('receipt.v1.json', $json))->toBe([]);
})->with([
    'a committed receipt without a changeset' => [str_replace('"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"', 'null', COMMITTED_RECEIPT), null, 'breaks a rule of the contract: A committed receipt needs a ChangesetId: the command committed a changeset.'],
    'a committed receipt without a position' => [str_replace('"4827"', 'null', COMMITTED_RECEIPT), null, 'breaks a rule of the contract: A committed receipt needs the commit position of its changeset.'],
    'a position above the largest xid8' => [str_replace('"4827"', '"18446744073709551616"', COMMITTED_RECEIPT), 'position', 'is not valid: A commit position is a Postgres xid8 in decimal without leading zeros, 0 to 18446744073709551615, for example "4827", got "18446744073709551616".'],
    'a rejected receipt with a position' => ['{"changeset_id":null,"consistency_token":null,"outcome":"rejected","position":"4827","projections":[],"retention_class":"standard","wait_level":"commit"}', null, 'breaks a rule of the contract: A rejected receipt has no position: the command committed nothing.'],
    'a rejected receipt with a consistency token' => ['{"changeset_id":null,"consistency_token":{"generation":1,"lsn":"0/1"},"outcome":"rejected","position":null,"projections":[],"retention_class":"standard","wait_level":"commit"}', null, 'breaks a rule of the contract: A rejected receipt has no consistency token: the command committed nothing.'],
    'a pending projection with a time' => [str_replace('"acknowledged_at":null', '"acknowledged_at":"2026-03-10T12:00:00Z"', COMMITTED_RECEIPT), 'projections[1]', 'breaks a rule of the contract: The pending projection "search" has no acknowledgement time.'],
    'a projection listed twice' => [str_replace('"search"', '"fragments"', COMMITTED_RECEIPT), null, 'breaks a rule of the contract: The projection "fragments" is listed twice in one receipt.'],
]);

it('keeps the outcome and the retention class of the Receipt enums', function (): void {
    $receipt = receiptCodec()->decode(COMMITTED_RECEIPT, ClassificationAccess::Sensitive);

    expect($receipt->outcome)->toBe(Outcome::Committed)
        ->and($receipt->retentionClass)->toBe(RetentionClass::Standard)
        ->and($receipt->projections[0]->acknowledgedAt?->format('Y-m-d\TH:i:s.uP'))->toBe('2026-03-10T12:00:00.250000+00:00')
        ->and(ReceiptCodecV1::VERSION)->toBe(1);
});
