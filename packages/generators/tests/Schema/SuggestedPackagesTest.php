<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\SuggestedPackages;
use LogicException;
use Opis\JsonSchema\CompliantValidator;
use Symfony\Component\Yaml\Yaml;

/*
 * cboxdk/cms suggests symfony/yaml and opis/json-schema instead of requiring them (GUARDRAILS 2.6),
 * so reading blueprints without them must fail with the names of the packages, not with a class
 * that cannot be found.
 */

it('names symfony/yaml and opis/json-schema with a class of each for the blueprint reader', function (): void {
    expect(SuggestedPackages::BLUEPRINT_READER)->toBe([
        'opis/json-schema' => CompliantValidator::class,
        'symfony/yaml' => Yaml::class,
    ]);
});

it('passes when every package is installed', function (): void {
    SuggestedPackages::require(SuggestedPackages::BLUEPRINT_READER, 'Reading the blueprint files');

    expect(class_exists(Yaml::class) && class_exists(CompliantValidator::class))->toBeTrue();
});

/**
 * @param  array<string, string>  $packages
 */
function suggestedPackagesFailure(array $packages): GenerationFailed
{
    try {
        SuggestedPackages::require($packages, 'Reading the blueprint files');
    } catch (GenerationFailed $failed) {
        return $failed;
    }

    throw new LogicException('SuggestedPackages::require() did not fail.');
}

it('fails with invalid configuration and names the missing package and the command that installs it', function (): void {
    $failed = suggestedPackagesFailure(['opis/json-schema' => CompliantValidator::class, 'symfony/yaml' => 'Acme\Missing\Yaml']);

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidConfig])
        ->and($failed->problems[0]->message)->toBe('Reading the blueprint files needs symfony/yaml, which cboxdk/cms suggests and does not require. Install it for development with `composer require --dev symfony/yaml`.');
});

it('names every missing package, sorted', function (): void {
    $failed = suggestedPackagesFailure(['symfony/yaml' => 'Acme\Missing\Yaml', 'opis/json-schema' => 'Acme\Missing\Validator']);

    expect($failed->problems[0]->message)->toBe('Reading the blueprint files needs opis/json-schema and symfony/yaml, which cboxdk/cms suggests and does not require. Install them for development with `composer require --dev opis/json-schema symfony/yaml`.');
});
