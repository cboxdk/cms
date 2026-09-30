<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Core\Seeding\Boundary\SeedingConfig;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedSettings;
use Illuminate\Config\Repository;
use InvalidArgumentException;

/*
 * cbox-cms.seeding: the service actor the seeder writes as.
 */

it('reads the service actor, or none for null or an empty text', function (): void {
    $actor = '01936f5e-8a2b-7c3d-9e4f-0000000047c1';

    expect(SeedingConfig::read(new Repository(['cbox-cms' => ['seeding' => ['service_actor' => $actor]]]))->serviceActor?->toString())->toBe($actor)
        ->and(SeedingConfig::read(new Repository(['cbox-cms' => ['seeding' => ['service_actor' => null]]]))->serviceActor)->toBeNull()
        ->and(SeedingConfig::read(new Repository(['cbox-cms' => ['seeding' => ['service_actor' => '']]]))->serviceActor)->toBeNull()
        ->and(SeedingConfig::read(new Repository([]))->serviceActor)->toBeNull();
});

it('refuses a service actor that is not a UUIDv7', function (mixed $value): void {
    expect(fn (): SeedSettings => SeedingConfig::read(new Repository(['cbox-cms' => ['seeding' => ['service_actor' => $value]]])))
        ->toThrow(InvalidArgumentException::class, 'cbox-cms.seeding.service_actor must be the UUIDv7 of a service actor, or null');
})->with([['not-an-id'], [42]]);
