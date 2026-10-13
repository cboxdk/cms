<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Core\Entries\Domain\RelationSortKey;
use DateTimeImmutable;
use LogicException;

/*
 * The sort key the kernel writes onto a relation's released edges (PRD 5.11, B2-T75): text whose
 * order byte by byte, the C collation of relations.sort_key, is the order of the values, for
 * every kind of sortable field, so the edges' index sorts and seeks as the type table's would.
 */

/**
 * The values sorted by their sort keys, byte by byte.
 *
 * @param  list<FieldValue>  $values
 * @return list<FieldValue>
 */
function bySortKey(array $values): array
{
    usort($values, static fn (FieldValue $a, FieldValue $b): int => strcmp((string) RelationSortKey::of($a), (string) RelationSortKey::of($b)));

    return $values;
}

it('sorts integers as numbers, the negative ones and the bounds included', function (): void {
    $sorted = [PHP_INT_MIN, -1_000_000, -10, -9, -1, 0, 1, 9, 10, 1_000_000, PHP_INT_MAX];
    $values = array_map(static fn (int $value): IntegerValue => new IntegerValue($value), $sorted);

    expect(array_map(static fn (FieldValue $value): int => $value instanceof IntegerValue ? $value->value : 0, bySortKey(array_reverse($values))))->toBe($sorted)
        ->and(RelationSortKey::of(new IntegerValue(42)))->toBe('10000000000000000042');
});

it('sorts decimals as numbers, whatever their digits before and after the point', function (): void {
    $sorted = ['-123.5', '-12.55', '-12.5', '-12', '-9.99', '-0.5', '-0.05', '0', '0.05', '0.5', '1', '9.99', '12', '12.5', '12.55', '123.5'];
    $shuffled = ['12', '-0.05', '123.5', '0', '-12.5', '9.99', '-123.5', '0.5', '-12', '12.55', '1', '-9.99', '0.05', '-12.55', '12.5', '-0.5'];

    expect(array_map(static fn (FieldValue $value): string => $value instanceof DecimalValue ? $value->value : '', bySortKey(array_map(static fn (string $value): DecimalValue => new DecimalValue($value), $shuffled))))->toBe($sorted)
        ->and(RelationSortKey::of(new DecimalValue('10.50')))->toBe(RelationSortKey::of(new DecimalValue('10.5')));
});

it('sorts days, instants in UTC, booleans and text as their values', function (): void {
    expect(bySortKey([new DateValue('2026-03-10'), new DateValue('1999-12-31'), new DateValue('2026-03-09')]))->toEqual([new DateValue('1999-12-31'), new DateValue('2026-03-09'), new DateValue('2026-03-10')])
        ->and(RelationSortKey::of(new DateTimeValue(new DateTimeImmutable('2026-03-10T09:00:00.25+01:00'))))->toBe('2026-03-10T08:00:00.250000Z')
        ->and(strcmp((string) RelationSortKey::of(new DateTimeValue(new DateTimeImmutable('2026-03-10T08:00:00.25Z'))), (string) RelationSortKey::of(new DateTimeValue(new DateTimeImmutable('2026-03-10T08:00:00.3Z')))))->toBeLessThan(0)
        ->and(bySortKey([new BooleanValue(true), new BooleanValue(false)]))->toEqual([new BooleanValue(false), new BooleanValue(true)])
        ->and(RelationSortKey::of(new TextValue('fixture_author')))->toBe('fixture_author');
});

it('gives no sort key for no value, and refuses a value of no sortable kind', function (): void {
    expect(RelationSortKey::of(new NullValue))->toBeNull()
        ->and(static fn (): ?string => RelationSortKey::of(new ListValue))->toThrow(LogicException::class, 'has no sort key');
});
