<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Seeding;

use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Core\Pipeline\Boundary\FieldValuesInput;
use Cbox\Cms\Core\Seeding\Domain\Dto\SeedableType;
use Cbox\Cms\Core\Seeding\Domain\FieldValueGenerator;
use Cbox\Cms\Core\Seeding\Domain\SeedProfile;
use Cbox\Cms\Core\Seeding\Domain\SeedProfiles;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/*
 * The seeder's values are a pure function of the profile, the type's rules and the randomizer
 * (GUARDRAILS 4.3): the same seed gives the same data set in every run and on every machine. This
 * holds the exact values of RulesType and SpreadType, which between them have every shape of rule
 * the generator reads, for a run of seeds under three profiles to Fixtures/field-values.golden.txt,
 * one entry's fields a line in the kernel's input form. Change the file only with a change to the
 * generator that is meant to change the data set, and bump the profiles' versions with it.
 */

const FIELD_VALUE_GOLDEN = __DIR__.'/Fixtures/field-values.golden.txt';

/**
 * The fields of every seed under every profile, a line each: `<profile> <type> <seed> <json>`.
 */
function goldenFieldValues(): string
{
    $profiles = [
        SeedProfiles::small(),
        new SeedProfile('even', 1, 10, 0.0, 0.0, 0.0, 50, 100, '2024-02-29', 400, 1.0),
        new SeedProfile('sparse', 1, 10, 2.0, 2.0, 2.5, 50, 0, '2026-12-31', 2, 7.5),
    ];
    $types = ['rules' => new RulesType, 'spread' => new SpreadType];
    $lines = [];

    foreach ($profiles as $profile) {
        $generator = new FieldValueGenerator($profile);

        foreach ($types as $name => $validator) {
            $type = seedableOf($validator);

            for ($seed = 0; $seed < 12; $seed++) {
                $fields = $generator->fields($type, ClassificationAccess::Internal, new Randomizer(new Xoshiro256StarStar(hash('sha256', 'golden:'.$seed, true))));
                $lines[] = sprintf('%s %s %d %s', $profile->label(), $name, $seed, json_encode(FieldValuesInput::of($fields), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            }
        }
    }

    return implode("\n", $lines)."\n";
}

function seedableOf(TypeValidator $validator): SeedableType
{
    $definition = $validator instanceof SpreadType ? SpreadType::definition() : RulesType::definition();

    return new SeedableType($definition, $validator->rules(), true);
}

it('gives exactly the committed values for each seed of each profile', function (): void {
    expect(goldenFieldValues())->toBe((string) file_get_contents(FIELD_VALUE_GOLDEN));
});
