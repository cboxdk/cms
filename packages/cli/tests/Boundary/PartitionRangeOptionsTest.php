<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Boundary;

use Cbox\Cms\Cli\Boundary\PartitionRangeOptions;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use InvalidArgumentException;

it('reads --from and --to into a range in UTC', function (): void {
    $range = PartitionRangeOptions::parse('2024-02-28T23:00:00+00:00', '2024-03-01T00:30:00.500000+02:00');

    expect($range)->toBeInstanceOf(PartitionRange::class)
        ->and($range?->from->format('Y-m-d\TH:i:s.uP'))->toBe('2024-02-28T23:00:00.000000+00:00')
        ->and($range?->to->format('Y-m-d\TH:i:s.uP'))->toBe('2024-02-29T22:30:00.500000+00:00');
});

it('gives no range when neither option is given', function (): void {
    expect(PartitionRangeOptions::parse(null, null))->toBeNull();
});

it('accepts a range of one instant', function (): void {
    expect(PartitionRangeOptions::parse('2026-01-01', '2026-01-01')?->to->format(DATE_ATOM))->toBe('2026-01-01T00:00:00+00:00');
});

it('refuses one option without the other, an invalid instant, and a range that ends before it starts', function (mixed $from, mixed $to, string $class, string $message): void {
    expect(static fn (): ?PartitionRange => PartitionRangeOptions::parse($from, $to))->toThrow($class, $message);
})->with([
    'only --from' => ['2026-01-01', null, InvalidArgumentException::class, 'Give both --from and --to, or neither.'],
    'only --to' => [null, '2026-01-01', InvalidArgumentException::class, 'Give both --from and --to, or neither.'],
    'not a date' => ['yesterday', '2026-01-01', InvalidArgumentException::class, 'The option --from is "yesterday".'],
    'bad --to before a missing --from' => [null, 'soon', InvalidArgumentException::class, 'The option --to is "soon".'],
    'backwards' => ['2026-01-02', '2026-01-01', InvalidPartitionPolicy::class, 'The range ends at 2026-01-01T00:00:00+00:00, before it starts at 2026-01-02T00:00:00+00:00.'],
]);
