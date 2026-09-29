<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Consistency;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Consistency\InvalidReceipt;

/*
 * The commit position (PRD 7.4, 8.4, 8.12): a Postgres xid8 in decimal, compared by its numeric
 * value, so a changeset's position and a read's snapshot xmin tell whether the read saw the
 * changeset.
 */

it('takes an xid8 as Postgres writes it, up to the largest', function (string $value): void {
    expect(new CommitPosition($value)->value)->toBe($value);
})->with(['0', '3', '4827', '9223372036854775807', '9223372036854775808', CommitPosition::MAX]);

it('refuses a value that is not an xid8 in decimal without leading zeros', function (string $value): void {
    expect(static fn (): CommitPosition => new CommitPosition($value))
        ->toThrow(InvalidReceipt::class, sprintf('A commit position is a Postgres xid8 in decimal without leading zeros, 0 to 18446744073709551615, for example "4827", got "%s".', $value));
})->with(['', '-1', '01', '00', ' 1', '1 ', '1.0', '1e3', '0x10', '18446744073709551616', '99999999999999999999', '100000000000000000000']);

it('compares positions by their numeric value, not as text', function (string $lower, string $higher): void {
    $a = new CommitPosition($lower);
    $b = new CommitPosition($higher);

    expect($a->isBelow($b))->toBeTrue()
        ->and($b->isBelow($a))->toBeFalse()
        ->and($a->isBelow($a))->toBeFalse()
        ->and($a->equals($b))->toBeFalse()
        ->and($a->equals(new CommitPosition($lower)))->toBeTrue();
})->with([
    'shorter below longer' => ['9', '10'],
    'same length' => ['4827', '4828'],
    'across the PHP int range' => ['9223372036854775807', '9223372036854775808'],
    'zero' => ['0', '1'],
    'the largest' => ['18446744073709551614', CommitPosition::MAX],
]);

it('tells that a read saw a changeset only when the changeset is below the read\'s position', function (): void {
    $changeset = new CommitPosition('4827');

    expect($changeset->seenBy(new CommitPosition('4828')))->toBeTrue()
        ->and($changeset->seenBy(new CommitPosition('4827')))->toBeFalse()
        ->and($changeset->seenBy(new CommitPosition('4826')))->toBeFalse();
});
