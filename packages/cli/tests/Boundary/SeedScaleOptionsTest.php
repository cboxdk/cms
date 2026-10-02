<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Boundary;

use Cbox\Cms\Cli\Boundary\SeedScaleOptions;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedRequest;
use InvalidArgumentException;

it('reads the profile, the seed and the entries, digits grouped with underscores', function (): void {
    $request = SeedScaleOptions::parse('scale', '0', '1_000_000');

    expect($request->profile->label())->toBe('scale@1')
        ->and($request->seed)->toBe(0)
        ->and($request->entries)->toBe(1_000_000)
        ->and($request->chunks())->toBe(5_000);
});

it('refuses an unknown profile, a missing option and a number that is not whole', function (mixed $profile, mixed $seed, mixed $entries, string $message): void {
    expect(static fn (): SeedRequest => SeedScaleOptions::parse($profile, $seed, $entries))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown profile' => ['huge', '1', '10', 'no seed profile "huge"'],
    'no profile' => [null, '1', '10', 'Give --profile'],
    'no entries' => ['small', '1', null, 'Give --entries'],
    'negative seed' => ['small', '-1', '10', 'Give --seed'],
    'zero entries' => ['small', '1', '0', 'A seed run seeds 1 to'],
    'leading zero' => ['small', '01', '10', 'Give --seed'],
    'decimal' => ['small', '1', '1.5', 'Give --entries'],
]);

it('names the profiles when no profile is given', function (): void {
    expect(static fn (): SeedRequest => SeedScaleOptions::parse(null, '1', '10'))
        ->toThrow(InvalidArgumentException::class, 'Give --profile as the name of a seed profile: scale, small.');
});
