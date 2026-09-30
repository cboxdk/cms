<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Schema\TypeCatalog;
use Cbox\Cms\Contracts\Validation\TypeValidators;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Pipeline\Domain\FieldValidation;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\FieldValueGenerator;
use Cbox\Cms\Core\Seeding\Domain\SeedProfiles;
use Cbox\Cms\Core\Seeding\Domain\SeedTypes;
use DateTimeImmutable;
use LogicException;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
 * The seeder's field values for any type, drawn from the rules of the type's generated validator
 * (GUARDRAILS 2.4, 4.3): the workbench's two types, whose fields cover every core field type and
 * rule of blueprint v1, pass the kernel's validation at the write and the release stage, the
 * actor's classification access and the encrypted fields are kept, and the values are skewed and
 * reproducible.
 */

/**
 * The workbench's type with the name, as the seeder sees it at the access.
 */
function seededType(string $name, ClassificationAccess $access = ClassificationAccess::Internal): SeedableType
{
    $catalog = SeedTypes::of(app(TypeCatalog::class)->all(), app(TypeValidators::class), $access);

    return array_find($catalog->types, static fn (SeedableType $type): bool => $type->definition->name->value === $name)
        ?? throw new LogicException(sprintf('The workbench has no seedable type %s.', $name));
}

function seededFields(SeedableType $type, int $seed, ClassificationAccess $access = ClassificationAccess::Internal): FieldValues
{
    return new FieldValueGenerator(SeedProfiles::small())->fields($type, $access, new Randomizer(new Xoshiro256StarStar(hash('sha256', 'fields:'.$seed, true))));
}

it('writes values that pass the kernel\'s validation at the write and the release stage', function (string $name): void {
    $type = seededType($name);
    $validation = app(FieldValidation::class);

    for ($seed = 0; $seed < 300; $seed++) {
        $fields = seededFields($type, $seed);

        foreach ([ValidationStage::Write, ValidationStage::Release] as $stage) {
            $report = $validation->validate($type->definition, $fields, $stage, new FieldPath('fields'));

            expect($report->errors)->toBe([], sprintf('%s, seed %d, %s stage', $name, $seed, $stage->value));
        }
    }
})->with(['app:fixture_article', 'app:fixture_measurement']);

it('writes only the fields the actor may write, every required one, and none stored encrypted', function (): void {
    $public = seededType('app:fixture_measurement', ClassificationAccess::Public);
    $article = seededType('app:fixture_article', ClassificationAccess::Sensitive);
    $seen = [];

    for ($seed = 0; $seed < 200; $seed++) {
        $measurement = seededFields($public, $seed, ClassificationAccess::Public)->own;
        $sensitive = seededFields($article, $seed, ClassificationAccess::Sensitive)->own;

        expect($measurement->get(new FieldHandle('fixture_station')))->toBeNull()
            ->and($measurement->get(new FieldHandle('fixture_sensor')))->toBeNull()
            ->and($measurement->get(new FieldHandle('fixture_reading')))->not->toBeNull()
            ->and($measurement->get(new FieldHandle('fixture_measured_at')))->not->toBeNull()
            ->and($sensitive->get(new FieldHandle('fixture_embargo')))->toBeNull()
            ->and($sensitive->get(new FieldHandle('fixture_featured')))->not->toBeNull();

        foreach ($sensitive->handles() as $handle) {
            $seen[$handle->value] = true;
        }
    }

    expect(array_keys($seen))->toContain('fixture_sources', 'fixture_body', 'fixture_topics', 'fixture_published_on');
});

it('gives the same values for the same randomizer state and other values for another', function (): void {
    $type = seededType('app:fixture_article');

    expect(seededFields($type, 5)->equals(seededFields($type, 5)))->toBeTrue()
        ->and(seededFields($type, 5)->equals(seededFields($type, 6)))->toBeFalse();
});

it('crowds dates towards the profile\'s anchor and keeps them within the field\'s bounds', function (): void {
    $type = seededType('app:fixture_article');
    $profile = SeedProfiles::small();
    $anchor = $profile->anchor;
    $recent = 0;
    $dated = 0;

    for ($seed = 0; $seed < 400; $seed++) {
        $date = seededFields($type, $seed)->own->get(new FieldHandle('fixture_published_on'));

        if (! $date instanceof DateValue) {
            continue;
        }

        $days = (int) new DateTimeImmutable($date->value)->diff($anchor)->days;
        $dated++;
        $recent += $days <= $profile->spanDays / 5 ? 1 : 0;

        expect($date->value >= '2000-01-01')->toBeTrue()
            ->and($date->value <= $anchor->format('Y-m-d'))->toBeTrue();
    }

    expect($dated)->toBeGreaterThan(250)
        ->and($recent / $dated)->toBeGreaterThan(0.5);
});
