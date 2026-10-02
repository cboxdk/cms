<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Cbox\Cms\Core\Seeding\Actions\SeedDataset;
use Cbox\Cms\Core\Tests\Seeding\DraftNoteType;
use Cbox\Cms\Core\Tests\Seeding\LockedType;
use Cbox\Cms\Core\Tests\Seeding\SeedActionWorld;
use Cbox\Cms\Core\Tests\Seeding\SpreadType;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Illuminate\Support\Facades\Artisan;
use Psr\Log\LoggerInterface;

/*
 * cms:seed-scale with SeedDataset on the fakes of the seeder's action tests: what it prints and how
 * it exits. SeedScaleCommandTest in the Postgres suite runs it on real Postgres.
 */

/**
 * @param  array<array-key, mixed>  $options
 * @return array{int, list<string>}
 */
function runSeedScale(array $options): array
{
    $status = Artisan::call('cms:seed-scale', $options);

    return [$status, array_values(array_filter(array_map(rtrim(...), explode("\n", Artisan::output())), static fn (string $line): bool => $line !== ''))];
}

it('seeds the entries and prints what it seeded and the wall time', function (): void {
    $world = new SeedActionWorld;
    app()->instance(SeedDataset::class, $world->dataset());

    [$status, $lines] = runSeedScale(['--entries' => '150', '--seed' => '4']);

    expect($status)->toBe(0)
        ->and($lines[0])->toBe(sprintf('Seeded 150 entries of profile small@1 with seed 4 in 2 chunks of up to 100 as %s, over 2 nodes and the types test:draft_note, test:locked: operation op_fake_1 is completed.', $world->actor->toString()))
        ->and($lines[1])->toMatch('/\AWall time: [0-9]+\.[0-9] s\.\z/')
        ->and($world->committer->pending)->toHaveCount(2);
});

it('exits 64 for invalid options and 78 for a service actor setting it cannot read', function (): void {
    app()->instance(SeedDataset::class, new SeedActionWorld()->dataset());

    [$unknown, $unknownLines] = runSeedScale(['--entries' => '10', '--profile' => 'huge']);
    [$missing] = runSeedScale([]);

    config(['cbox-cms.seeding.service_actor' => 'not-an-id']);
    [$config, $configLines] = runSeedScale(['--entries' => '10']);

    expect([$unknown, $missing, $config])->toBe([64, 64, 78])
        ->and($unknownLines[0])->toContain('no seed profile "huge"')
        ->and($configLines[0])->toContain('cbox-cms.seeding.service_actor must be the UUIDv7');
});

it('exits with the refusal\'s code and logs it when the seeder refuses', function (): void {
    $world = new SeedActionWorld;
    $logger = new RecordingLogger;
    app()->instance(LoggerInterface::class, $logger);
    app()->instance(SeedDataset::class, $world->dataset(nodes: false));

    [$status, $lines] = runSeedScale(['--entries' => '10']);

    expect($status)->toBe(77)
        ->and($lines[0])->toContain('reaches no node')
        ->and($logger->records[0][1])->toBe('The seeder refused to seed.')
        ->and($logger->records[0][2])->toBe(['profile' => 'small@1', 'seed' => 1, 'entries' => 10]);
});

it('warns about each type it leaves out and prints the wall time in seconds', function (): void {
    $world = new SeedActionWorld;
    app()->instance(SeedDataset::class, $world->dataset(types: new FakeTypeCatalog(LockedType::definition(), DraftNoteType::definition(), SpreadType::definition())));
    $started = hrtime(true);

    [$status, $lines] = runSeedScale(['--entries' => '10', '--seed' => '1']);

    $elapsed = (hrtime(true) - $started) / 1e9;

    expect($status)->toBe(0)
        ->and($lines[0])->toBe('The type test:spread has no generated validator; run cms:generate.')
        ->and($lines[1])->toStartWith('Seeded 10 entries of profile small@1 with seed 1')
        ->and(preg_match('/\AWall time: ([0-9]+\.[0-9]) s\.\z/', $lines[2], $match))->toBe(1)
        ->and((float) ($match[1] ?? 'NAN'))->toBeLessThanOrEqual(round($elapsed, 1) + 0.1);
});
