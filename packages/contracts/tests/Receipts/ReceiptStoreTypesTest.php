<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Receipts;

use Cbox\Cms\Contracts\Consistency\DuplicateReceipt;
use Cbox\Cms\Contracts\Consistency\RetentionClass;
use Cbox\Cms\Contracts\Consistency\TransactionRequired;
use Cbox\Cms\Contracts\Consistency\UnsupportedIsolation;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\Uuid7;
use Cbox\Cms\Contracts\Receipts\ProjectionStatus;
use Cbox\Cms\Contracts\Receipts\StoredReceipt;
use Cbox\Cms\Contracts\ReceiptStore;
use DateTimeImmutable;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use RuntimeException;

/*
 * The types around the ReceiptStore contract (PRD 4, 8.4): when a receipt expires, the errors a
 * store throws, and the shape of the contract itself.
 */

function changesetAt(string $time): ChangesetId
{
    return new ChangesetId(Uuid7::lowestAt(Uuid7::unixMillisecondsOf(new DateTimeImmutable($time))));
}

it('expires a Standard receipt exactly seven days after the time in its changeset id, in UTC', function (string $created, string $expires): void {
    $expiresAt = RetentionClass::Standard->expiresAt(changesetAt($created));

    expect($expiresAt?->format('Y-m-d\TH:i:s.u e'))->toBe($expires);
})->with([
    'with milliseconds' => ['2026-01-01T00:00:00.123456+00:00', '2026-01-08T00:00:00.123000 UTC'],
    'across a DST change in the caller\'s zone' => ['2026-03-25T12:00:00.999+01:00', '2026-04-01T11:00:00.999000 UTC'],
    'at the epoch' => ['1970-01-01T00:00:00+00:00', '1970-01-08T00:00:00.000000 UTC'],
    'at the end of 9999' => ['9999-12-31T23:59:59.999+00:00', '10000-01-07T23:59:59.999000 UTC'],
]);

it('gives no fixed expiry for an Evidence receipt', function (): void {
    expect(RetentionClass::Evidence->expiresAt(changesetAt('2026-01-01T00:00:00Z')))->toBeNull();
});

it('names the changeset of a duplicate receipt', function (): void {
    $changesetId = changesetAt('2026-01-01T00:00:00Z');
    $duplicate = DuplicateReceipt::forChangeset($changesetId);

    expect($duplicate)->toBeInstanceOf(RuntimeException::class)
        ->and($duplicate->getMessage())->toContain($changesetId->toString());
});

it('reports a store outside a transaction as a bug in the caller that stored nothing', function (): void {
    $refused = TransactionRequired::forStore();

    expect($refused)->toBeInstanceOf(LogicException::class)
        ->and($refused->getMessage())->toBe('ReceiptStore::store() runs inside the caller\'s command transaction, and the connection has none open. The receipt commits and rolls back with its changeset, and the transaction holds the lock that keeps one receipt per changeset. Nothing was stored.');
});

it('names the isolation level a store refuses and the one it needs', function (): void {
    $unsupported = UnsupportedIsolation::receiptStore('repeatable read');

    expect($unsupported)->toBeInstanceOf(LogicException::class)
        ->and($unsupported->getMessage())->toContain('REPEATABLE READ')
        ->and($unsupported->getMessage())->toContain('needs the caller\'s transaction at READ COMMITTED');
});

it('has store, find and markProjection with typed ids and no string ids', function (): void {
    $methods = [];

    foreach (new ReflectionClass(ReceiptStore::class)->getMethods() as $method) {
        $types = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $types[] = $type instanceof ReflectionNamedType ? $type->getName() : 'untyped';
        }

        $return = $method->getReturnType();
        $methods[$method->getName()] = [$types, (string) $return];
    }

    expect($methods)->toBe([
        'store' => [[StoredReceipt::class], 'void'],
        'find' => [[ChangesetId::class], '?Cbox\Cms\Contracts\Receipts\StoredReceipt'],
        'markProjection' => [[ChangesetId::class, ProjectionStatus::class], 'bool'],
    ]);
});

it('stores only the facts of a changeset, never the outcome or the wait level of a call', function (): void {
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        new ReflectionClass(StoredReceipt::class)->getProperties(),
    );

    sort($properties);

    expect($properties)->toBe(['changesetId', 'position', 'projections', 'retentionClass']);
});
