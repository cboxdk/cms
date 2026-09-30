<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Core\Seeding\Domain\Dto\SeedRequest;
use Cbox\Cms\Core\Seeding\Domain\InvalidSeed;
use Cbox\Cms\Core\Seeding\Domain\SeedProfile;
use Cbox\Cms\Core\Seeding\Domain\SeedProfiles;
use Cbox\Cms\Core\Seeding\Domain\SeedRequestLimits;
use Cbox\Cms\Core\Seeding\Domain\SeedText;
use Cbox\Cms\Core\Seeding\Domain\ZipfDistribution;
use InvalidArgumentException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
 * The seed profiles, the chunks of a run and the Zipf distribution that skews a data set
 * (GUARDRAILS 4.3, PRD 23).
 */

it('has a small and a scale profile, each versioned, and refuses another name', function (): void {
    expect(SeedProfiles::named('small')->label())->toBe('small@1')
        ->and(SeedProfiles::named('scale')->label())->toBe('scale@1')
        ->and(SeedProfiles::named('scale')->chunkSize)->toBe(200)
        ->and(SeedProfiles::small()->chunkSize)->toBe(100)
        ->and(SeedProfiles::names())->toBe(['scale', 'small'])
        ->and(fn (): SeedProfile => SeedProfiles::named('huge'))->toThrow(InvalidSeed::class, 'no seed profile "huge"');
});

it('refuses a profile out of its bounds', function (callable $profile): void {
    expect($profile)->toThrow(InvalidSeed::class);
})->with([
    'name' => [fn (): SeedProfile => new SeedProfile('Custom', 1, 10, 1.0, 1.0, 1.0, 50, 50, '2026-01-01', 10, 2.0)],
    'version' => [fn (): SeedProfile => new SeedProfile('custom', 0, 10, 1.0, 1.0, 1.0, 50, 50, '2026-01-01', 10, 2.0)],
    'chunk size' => [fn (): SeedProfile => new SeedProfile('custom', 1, 1001, 1.0, 1.0, 1.0, 50, 50, '2026-01-01', 10, 2.0)],
    'skew' => [fn (): SeedProfile => new SeedProfile('custom', 1, 10, 1.0, -0.5, 1.0, 50, 50, '2026-01-01', 10, 2.0)],
    'released' => [fn (): SeedProfile => new SeedProfile('custom', 1, 10, 1.0, 1.0, 1.0, 101, 50, '2026-01-01', 10, 2.0)],
    'filled' => [fn (): SeedProfile => new SeedProfile('custom', 1, 10, 1.0, 1.0, 1.0, 50, -1, '2026-01-01', 10, 2.0)],
    'anchor' => [fn (): SeedProfile => new SeedProfile('custom', 1, 10, 1.0, 1.0, 1.0, 50, 50, '2026-02-30', 10, 2.0)],
    'span' => [fn (): SeedProfile => new SeedProfile('custom', 1, 10, 1.0, 1.0, 1.0, 50, 50, '2026-01-01', 0, 2.0)],
    'date skew' => [fn (): SeedProfile => new SeedProfile('custom', 1, 10, 1.0, 1.0, 1.0, 50, 50, '2026-01-01', 10, 0.5)],
]);

it('splits a run into chunks of the profile, the last holding what is left, each its own unit of work', function (): void {
    $request = new SeedRequest(SeedProfiles::small(), 7, 250);

    expect($request->chunks())->toBe(3)
        ->and([$request->firstOf(0), $request->firstOf(1), $request->firstOf(2)])->toBe([0, 100, 200])
        ->and([$request->sizeOf(0), $request->sizeOf(2)])->toBe([100, 50])
        ->and($request->unitOf(2))->toBe('seed:small@1:7:2:50')
        ->and($request->operationKey())->toBe('small@1:7:250')
        ->and(fn (): int => $request->firstOf(3))->toThrow(InvalidSeed::class, 'chunks 0 to 2')
        ->and(fn (): SeedRequest => new SeedRequest(SeedProfiles::small(), -1, 1))->toThrow(InvalidSeed::class, 'seed')
        ->and(fn (): SeedRequest => new SeedRequest(SeedProfiles::small(), 1, 0))->toThrow(InvalidSeed::class, 'entries')
        ->and(fn (): SeedRequest => new SeedRequest(SeedProfiles::small(), 1, SeedRequestLimits::MAX_ENTRIES + 1))->toThrow(InvalidSeed::class);
});

it('draws the first position of a Zipf distribution most often and evenly with an exponent of 0', function (): void {
    $zipf = new ZipfDistribution(5, 1.0);
    $random = new Randomizer(new Xoshiro256StarStar(str_repeat('z', 32)));
    $counts = array_fill(0, 5, 0);

    for ($draw = 0; $draw < 20_000; $draw++) {
        $counts[$zipf->pick($random)]++;
    }

    $even = new ZipfDistribution(4, 0.0);

    expect(array_sum(array_map($zipf->share(...), range(0, 4))))->toEqualWithDelta(1.0, 1e-9)
        ->and($zipf->share(0))->toEqualWithDelta(1 / (1 + 1 / 2 + 1 / 3 + 1 / 4 + 1 / 5), 1e-9)
        ->and($counts[0])->toBeGreaterThan($counts[1])
        ->and($counts[1])->toBeGreaterThan($counts[4])
        ->and($counts[0] / 20_000)->toEqualWithDelta($zipf->share(0), 0.02)
        ->and(array_map($even->share(...), range(0, 3)))->each->toEqualWithDelta(0.25, 1e-9)
        ->and($zipf->share(5))->toBe(0.0)
        ->and(fn (): ZipfDistribution => new ZipfDistribution(0, 1.0))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ZipfDistribution => new ZipfDistribution(3, -1.0))->toThrow(InvalidArgumentException::class);
});

it('writes words of the neutral list between the lengths asked for', function (): void {
    $random = new Randomizer(new Xoshiro256StarStar(str_repeat('w', 32)));

    for ($text = 0; $text < 200; $text++) {
        $words = SeedText::words($random, 2, 8, 12, 40);

        expect(strlen($words))->toBeGreaterThanOrEqual(12)->toBeLessThanOrEqual(40)
            ->and($words)->toMatch('/\A[a-z ]+\z/');
    }

    expect(SeedText::words($random, 1, 1, 30, 30))->toHaveLength(30);
});
