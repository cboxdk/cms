<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Postgres;

use Cbox\Cms\Core\Tests\Seeding\SeedWorld;
use Illuminate\Support\Facades\Artisan;

/*
 * cms:seed-scale on real Postgres (GUARDRAILS 4.3): it seeds through the kernel as the service
 * actor cbox-cms.seeding.service_actor names, and a second run with the same options finds the
 * operation completed and seeds nothing again.
 */

afterEach(function (): void {
    SeedWorld::cleanUp();
});

it('seeds the entries as the configured service actor, and seeds nothing again for the same options', function (): void {
    new SeedWorld;

    $first = Artisan::call('cms:seed-scale', ['--entries' => '150', '--seed' => '9']);
    $output = Artisan::output();
    $rows = SeedWorld::rows();
    $second = Artisan::call('cms:seed-scale', ['--entries' => '150', '--seed' => '9']);

    expect([$first, $second])->toBe([0, 0])
        ->and($output)->toContain('Seeded 150 entries of profile small@1 with seed 9 in 2 chunks of up to 100')
        ->and($output)->toContain('is completed.')
        ->and($rows['entries'])->toBe(150)
        ->and($rows['changesets'])->toBe(2)
        ->and(SeedWorld::rows())->toBe($rows);
});

it('refuses to seed without a service actor', function (): void {
    new SeedWorld;
    config(['cbox-cms.seeding.service_actor' => null]);

    expect(Artisan::call('cms:seed-scale', ['--entries' => '10']))->toBe(78)
        ->and(Artisan::output())->toContain('cbox-cms.seeding.service_actor names none')
        ->and(SeedWorld::rows()['entries'])->toBe(0);
});
