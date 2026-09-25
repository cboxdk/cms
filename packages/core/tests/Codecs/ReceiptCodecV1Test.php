<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Consistency\InvalidReceipt;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\ProjectionName;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\InvalidUuid7;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\Receipt;
use Cbox\Cms\Core\Codecs\Boundary\MalformedReceiptJson;
use Cbox\Cms\Core\Codecs\Boundary\ReceiptCodecV1;
use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\Assert;

/*
 * The temporary M0 receipt codec (GUARDRAILS 2.2): round trips for every outcome, the fixed bytes
 * with sorted keys, null and missing fields, and typed failures on bad input.
 */

const CHANGESET = '01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f';

/** A committed receipt in its exact version 1 form. */
const COMMITTED_JSON = '{"changeset_id":"01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f","codec_version":1,"outcome":"committed",'
    .'"projections":[{"acknowledged_at":"2026-01-01T00:00:00.123456Z","projection":"edge","state":"acknowledged"},'
    .'{"acknowledged_at":null,"projection":"fragments","state":"pending"}],'
    .'"retention_class":"standard","wait_level":"origin"}';

function codec(): ReceiptCodecV1
{
    return new ReceiptCodecV1;
}

/**
 * @return list<ProjectionStatus>
 */
function statuses(): array
{
    return [
        ProjectionStatus::pending(new ProjectionName('fragments')),
        ProjectionStatus::acknowledged(new ProjectionName('edge'), new DateTimeImmutable('2026-01-01T00:00:00.123456Z')),
        ProjectionStatus::acknowledged(new ProjectionName('acme.search'), new DateTimeImmutable('9999-12-31T23:59:59.999999Z')),
    ];
}

/**
 * A committed receipt as a PHP value, with one field replaced, for the decoding failures.
 *
 * @return array<string, mixed>
 */
function committedDocument(): array
{
    return json_decode(COMMITTED_JSON, true, 8, JSON_THROW_ON_ERROR);
}

/**
 * @param  array<mixed>  $document
 */
function json(array $document): string
{
    return json_encode($document, JSON_THROW_ON_ERROR);
}

function expectMalformed(string $json, string $message): MalformedReceiptJson
{
    try {
        codec()->decode($json);
    } catch (MalformedReceiptJson $exception) {
        expect($exception->getMessage())->toContain($message);

        return $exception;
    }

    Assert::fail('Expected MalformedReceiptJson.');
}

/**
 * Asserts that every JSON object in the value lists its keys in sorted order.
 */
function assertSortedKeys(mixed $value, string $path = '$'): void
{
    if (! is_array($value)) {
        return;
    }

    if (! array_is_list($value)) {
        $keys = array_map(strval(...), array_keys($value));
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        Assert::assertSame($sorted, $keys, "The keys of {$path} are not sorted.");
    }

    foreach ($value as $key => $item) {
        assertSortedKeys($item, "{$path}.{$key}");
    }
}

it('round-trips every outcome with an empty and a non-empty projection list', function (Receipt $receipt): void {
    $json = codec()->encode($receipt);
    $decoded = codec()->decode($json);

    expect($decoded)->toEqual($receipt)
        ->and(codec()->encode($decoded))->toBe($json);
})->with([
    'committed, no projections' => fn (): Receipt => Receipt::committed(ChangesetId::fromString(CHANGESET), WaitLevel::Commit, RetentionClass::Standard),
    'committed, projections' => fn (): Receipt => Receipt::committed(ChangesetId::fromString(CHANGESET), WaitLevel::Origin, RetentionClass::Evidence, statuses()),
    'committed_wait_timeout, no projections' => fn (): Receipt => Receipt::committedWaitTimeout(ChangesetId::fromString(CHANGESET), WaitLevel::Edge, RetentionClass::Standard),
    'committed_wait_timeout, projections' => fn (): Receipt => Receipt::committedWaitTimeout(ChangesetId::fromString(CHANGESET), WaitLevel::Verified, RetentionClass::Evidence, statuses()),
    'rejected' => fn (): Receipt => Receipt::rejected(WaitLevel::Propagated, RetentionClass::Standard),
    'dry_run' => fn (): Receipt => Receipt::dryRun(WaitLevel::Commit, RetentionClass::Evidence),
]);

it('round-trips every wait level and retention class', function (WaitLevel $waitLevel, RetentionClass $retentionClass): void {
    $receipt = Receipt::committed(ChangesetId::fromString(CHANGESET), $waitLevel, $retentionClass, statuses());

    expect(codec()->decode(codec()->encode($receipt)))->toEqual($receipt);
})->with(WaitLevel::cases())->with(RetentionClass::cases());

it('writes the fixed version 1 form', function (): void {
    $receipt = Receipt::committed(ChangesetId::fromString(CHANGESET), WaitLevel::Origin, RetentionClass::Standard, [
        ProjectionStatus::pending(new ProjectionName('fragments')),
        ProjectionStatus::acknowledged(new ProjectionName('edge'), new DateTimeImmutable('2026-01-01T01:00:00.123456+01:00')),
    ]);

    expect(codec()->encode($receipt))->toBe(COMMITTED_JSON)
        ->and(codec()->decode(COMMITTED_JSON))->toEqual($receipt);
});

it('writes an absent value as null and never leaves a field out', function (): void {
    expect(codec()->encode(Receipt::rejected(WaitLevel::Commit, RetentionClass::Standard)))
        ->toBe('{"changeset_id":null,"codec_version":1,"outcome":"rejected","projections":[],"retention_class":"standard","wait_level":"commit"}')
        ->and(codec()->encode(Receipt::dryRun(WaitLevel::Edge, RetentionClass::Evidence)))
        ->toBe('{"changeset_id":null,"codec_version":1,"outcome":"dry_run","projections":[],"retention_class":"evidence","wait_level":"edge"}');
});

it('gives byte-identical JSON with sorted keys when the same value is encoded twice', function (): void {
    $receipt = Receipt::committedWaitTimeout(ChangesetId::fromString(CHANGESET), WaitLevel::Edge, RetentionClass::Evidence, statuses());
    $equal = Receipt::committedWaitTimeout(ChangesetId::fromString(CHANGESET), WaitLevel::Edge, RetentionClass::Evidence, array_reverse(statuses()));

    $first = codec()->encode($receipt);

    expect(codec()->encode($receipt))->toBe($first)
        ->and(new ReceiptCodecV1()->encode($receipt))->toBe($first)
        ->and(codec()->encode($equal))->toBe($first)
        ->and($first)->not->toContain(' ')
        ->and($first)->not->toContain("\n");

    assertSortedKeys(json_decode($first, true, 8, JSON_THROW_ON_ERROR));
});

it('writes a time in UTC with six fraction digits and a Z', function (): void {
    $receipt = Receipt::committed(ChangesetId::fromString(CHANGESET), WaitLevel::Commit, RetentionClass::Standard, [
        ProjectionStatus::acknowledged(new ProjectionName('edge'), new DateTimeImmutable('2026-03-29T03:30:00+02:00')),
    ]);

    expect(codec()->encode($receipt))->toContain('"acknowledged_at":"2026-03-29T01:30:00.000000Z"');
});

it('fails with MalformedReceiptJson on an unknown outcome', function (): void {
    expectMalformed(json(['outcome' => 'committed_later'] + committedDocument()), '$.outcome is "committed_later", which is not one of: rejected, committed, committed_wait_timeout, dry_run.');
});

it('fails with MalformedReceiptJson on a malformed changeset id and chains the id error', function (string $id): void {
    $exception = expectMalformed(json(['changeset_id' => $id] + committedDocument()), '$.changeset_id is not a changeset id');

    expect($exception->getPrevious())->toBeInstanceOf(InvalidUuid7::class);
})->with([
    'not a uuid' => 'not-a-uuid',
    'version 4' => '550e8400-e29b-41d4-a716-446655440000',
    'empty' => '',
    'braces' => '{01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f}',
]);

it('fails with MalformedReceiptJson on an unknown codec version', function (int $version): void {
    expectMalformed(json(['codec_version' => $version] + committedDocument()), "The receipt has codec_version {$version}. This codec reads version 1 only.");
})->with([0, 2, 99]);

it('checks the codec version before the fields, so a newer form fails as an unknown version', function (): void {
    expectMalformed(json(['codec_version' => 2, 'position' => '0/16B3748']), 'The receipt has codec_version 2.');
});

it('fails with MalformedReceiptJson when the codec version is missing or not an integer', function (mixed $version, string $message): void {
    $document = committedDocument();
    unset($document['codec_version']);

    if ($version !== 'missing') {
        $document['codec_version'] = $version;
    }

    expectMalformed(json($document), $message);
})->with([
    'missing' => ['missing', '$ has no field "codec_version"'],
    'string' => ['1', '$.codec_version must be an integer, got string.'],
    'float' => [1.5, '$.codec_version must be an integer, got float.'],
    'null' => [null, '$.codec_version must be an integer, got null.'],
]);

it('fails with MalformedReceiptJson on JSON that is not a receipt object', function (string $json, string $message): void {
    expectMalformed($json, $message);
})->with([
    'invalid JSON' => ['{"codec_version":1,', 'A receipt is not valid JSON'],
    'empty string' => ['', 'A receipt is not valid JSON'],
    'array' => ['[]', '$ must be an object, got array.'],
    'string' => ['"committed"', '$ must be an object, got string.'],
    'null' => ['null', '$ must be an object, got null.'],
    'too deep' => ['{"codec_version":1,"x":[[[[[[[[1]]]]]]]]}', 'A receipt is not valid JSON'],
]);

it('chains the JSON error when the input is not JSON', function (): void {
    expect(expectMalformed('{', 'not valid JSON')->getPrevious())->toBeInstanceOf(JsonException::class);
});

it('fails with MalformedReceiptJson on a missing field, since an absent value is null and not left out', function (string $field): void {
    $document = committedDocument();
    unset($document[$field]);

    expectMalformed(json($document), "\$ has no field \"{$field}\"");
})->with(['changeset_id', 'outcome', 'projections', 'retention_class', 'wait_level']);

it('fails with MalformedReceiptJson on an unknown field', function (): void {
    expectMalformed(json(['position' => '0/16B3748'] + committedDocument()), '$ has an unknown field "position".');
});

it('fails with MalformedReceiptJson on unknown enum values', function (string $field, string $value): void {
    expectMalformed(json([$field => $value] + committedDocument()), "\$.{$field} is \"{$value}\", which is not one of");
})->with([
    ['wait_level', 'cdn'],
    ['wait_level', 'Commit'],
    ['retention_class', 'forever'],
    ['outcome', 'Committed'],
]);

it('fails with MalformedReceiptJson on values of the wrong type', function (string $field, mixed $value, string $message): void {
    expectMalformed(json([$field => $value] + committedDocument()), $message);
})->with([
    ['outcome', null, '$.outcome must be a string, got null.'],
    ['outcome', 1, '$.outcome must be a string, got int.'],
    ['changeset_id', 42, '$.changeset_id must be a string or null, got int.'],
    ['wait_level', ['commit'], '$.wait_level must be a string, got array.'],
    ['projections', null, '$.projections must be an array, got null.'],
    ['projections', ['edge' => 'pending'], '$.projections must be an array, got stdClass.'],
]);

it('fails with MalformedReceiptJson on a bad projection status and names its path', function (mixed $status, string $message): void {
    expectMalformed(json(['projections' => [$status]] + committedDocument()), $message);
})->with([
    'not an object' => ['edge', '$.projections[0] must be an object, got string.'],
    'missing field' => [['projection' => 'edge', 'state' => 'pending'], '$.projections[0] has no field "acknowledged_at"'],
    'unknown field' => [['acknowledged_at' => null, 'projection' => 'edge', 'state' => 'pending', 'attempts' => 1], '$.projections[0] has an unknown field "attempts".'],
    'unknown state' => [['acknowledged_at' => null, 'projection' => 'edge', 'state' => 'done'], '$.projections[0].state is "done", which is not one of: pending, acknowledged.'],
    'name not a string' => [['acknowledged_at' => null, 'projection' => 7, 'state' => 'pending'], '$.projections[0].projection must be a string, got int.'],
    'bad name' => [['acknowledged_at' => null, 'projection' => 'Edge', 'state' => 'pending'], '$.projections[0] is not a valid receipt: A projection name is dot-separated snake_case'],
    'acknowledged without time' => [['acknowledged_at' => null, 'projection' => 'edge', 'state' => 'acknowledged'], '$.projections[0] is not a valid receipt: The acknowledged projection "edge" needs the time'],
    'pending with time' => [['acknowledged_at' => '2026-01-01T00:00:00.000000Z', 'projection' => 'edge', 'state' => 'pending'], '$.projections[0] is not a valid receipt: The pending projection "edge" has no acknowledgement time.'],
]);

it('fails with MalformedReceiptJson on a time that is not UTC with six fraction digits', function (mixed $time, string $message): void {
    $status = ['acknowledged_at' => $time, 'projection' => 'edge', 'state' => 'acknowledged'];

    expectMalformed(json(['projections' => [$status]] + committedDocument()), $message);
})->with([
    'no fraction' => ['2026-01-01T00:00:00Z', 'A time is UTC with microseconds'],
    'milliseconds' => ['2026-01-01T00:00:00.123Z', 'A time is UTC with microseconds'],
    'offset' => ['2026-01-01T00:00:00.000000+00:00', 'A time is UTC with microseconds'],
    'lower case z' => ['2026-01-01T00:00:00.000000z', 'A time is UTC with microseconds'],
    'space' => ['2026-01-01 00:00:00.000000Z', 'A time is UTC with microseconds'],
    'day that does not exist' => ['2026-02-30T00:00:00.000000Z', '$.projections[0].acknowledged_at is "2026-02-30T00:00:00.000000Z"'],
    'hour 24' => ['2026-01-01T24:00:00.000000Z', 'A time is UTC with microseconds'],
    'before 1970' => ['1969-12-31T23:59:59.999999Z', 'The time must be from 1970 to the end of 9999'],
    'number' => [1767225600, '$.projections[0].acknowledged_at must be a string or null, got int.'],
]);

it('fails with MalformedReceiptJson when the fields break the receipt invariants, and chains InvalidReceipt', function (array $replace, string $message): void {
    $exception = expectMalformed(json($replace + committedDocument()), $message);

    expect($exception->getPrevious())->toBeInstanceOf(InvalidReceipt::class);
})->with([
    'committed without changeset' => [['changeset_id' => null], '$ is not a valid receipt: A committed receipt needs a ChangesetId'],
    'rejected with changeset' => [['outcome' => 'rejected', 'projections' => []], '$ is not a valid receipt: A rejected receipt has no ChangesetId'],
    'dry run with projections' => [['outcome' => 'dry_run', 'changeset_id' => null], '$ is not a valid receipt: A dry_run receipt has no projection statuses'],
    'duplicate projection' => [
        ['projections' => [
            ['acknowledged_at' => null, 'projection' => 'edge', 'state' => 'pending'],
            ['acknowledged_at' => null, 'projection' => 'edge', 'state' => 'pending'],
        ]],
        '$ is not a valid receipt: The projection "edge" is listed twice',
    ],
]);

it('reads projections in any order into the same receipt', function (): void {
    $document = committedDocument();
    $projections = $document['projections'];
    Assert::assertIsArray($projections);
    $document['projections'] = array_reverse($projections);

    expect(codec()->decode(json($document)))->toEqual(codec()->decode(COMMITTED_JSON));
});
