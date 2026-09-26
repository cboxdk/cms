<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Boundary;

use Cbox\Cms\Cli\Boundary\TimeOption;
use InvalidArgumentException;

it('reads a date as midnight UTC and a time with an offset in UTC', function (string $value, string $expected): void {
    expect(TimeOption::parse('from', $value)?->format('Y-m-d\TH:i:s.uP'))->toBe($expected);
})->with([
    'date' => ['2031-05-01', '2031-05-01T00:00:00.000000+00:00'],
    'time' => ['2031-05-01T12:30:00+02:00', '2031-05-01T10:30:00.000000+00:00'],
    'fraction' => ['2031-05-01T12:30:00.123456Z', '2031-05-01T12:30:00.123456+00:00'],
    'leap day' => ['2024-02-29', '2024-02-29T00:00:00.000000+00:00'],
]);

it('returns null for an option that was not given', function (): void {
    expect(TimeOption::parse('from', null))->toBeNull();
});

it('refuses anything else and names the option', function (mixed $value, string $shown): void {
    expect(static fn (): mixed => TimeOption::parse('to', $value))
        ->toThrow(InvalidArgumentException::class, sprintf('The option --to is "%s".', $shown));
})->with([
    'words' => ['tomorrow', 'tomorrow'],
    'impossible date' => ['2026-02-30', '2026-02-30'],
    'no offset' => ['2026-01-01T10:00:00', '2026-01-01T10:00:00'],
    'empty' => ['', ''],
    'array' => [['2026-01-01'], 'array'],
]);
