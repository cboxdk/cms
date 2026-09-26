<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\PackageManifest;
use Cbox\Cms\Tests\Support\Phpstan;
use RectorLaravel\Set\LaravelLevelSetList;

/*
 * The testkit ships the one versioned configuration for Pint, Rector and PHPStan that the
 * kernel, the app template and addons share (GUARDRAILS 10, gates 1 to 3).
 */

function testkitConfig(string $file): string
{
    return __DIR__.'/../config/'.$file;
}

it('ships the PHPStan, Rector and Pint configuration', function (string $file): void {
    expect(testkitConfig($file))->toBeReadableFile();
})->with(['phpstan.neon', 'rector.php', 'pint.json']);

it('requires the tools itself, so an addon that requires only the testkit can run them', function (): void {
    expect(PackageManifest::of('testkit')->requires())->toHaveKeys([
        'phpstan/phpstan',
        'larastan/larastan',
        'rector/rector',
        'driftingly/rector-laravel',
        'laravel/pint',
    ]);
});

it('runs PHPStan at level 10 with Larastan and ignores no errors', function (): void {
    $parameters = Phpstan::parameters('packages/testkit/config/phpstan.neon');

    expect($parameters->value('level'))->toBe(10)
        ->and($parameters->value('ignoreErrors'))->toBe([])
        ->and($parameters->strings('bootstrapFiles'))->toContain(Phpstan::root().'/vendor/larastan/larastan/bootstrap.php')
        ->and($parameters->value('checkUninitializedProperties'))->toBeTrue()
        ->and($parameters->value('checkMissingCallableSignature'))->toBeTrue()
        ->and($parameters->value('reportAnyTypeWideningInVarTag'))->toBeTrue()
        ->and($parameters->value('reportUnmatchedIgnoredErrors'))->toBeTrue();
});

it('boots Laravel for Larastan in one PHPStan process at a time', function (string $configuration): void {
    // The root reaches the testkit through its vendor symlink, so compare real paths.
    $bootstrapFiles = array_map(realpath(...), Phpstan::parameters($configuration)->strings('bootstrapFiles'));
    $larastan = realpath(Phpstan::root().'/vendor/larastan/larastan/bootstrap.php');
    $position = array_search($larastan, $bootstrapFiles, true);

    // PHPStan's workers boot at the same moment, and each boot writes shared files in Testbench's
    // skeleton; LaravelBootLockTest covers the lock itself.
    expect($position)->toBeInt()
        ->and(array_slice($bootstrapFiles, (int) $position - 1, 3))->toBe([
            realpath(testkitConfig('phpstan-boot-lock.php')),
            $larastan,
            realpath(testkitConfig('phpstan-boot-unlock.php')),
        ]);
})->with(['packages/testkit/config/phpstan.neon', 'phpstan.neon']);

it('adds no paths, so every repo decides what it analyses', function (): void {
    expect(Phpstan::parameters('packages/testkit/config/phpstan.neon')->value('analysedPathsFromConfig'))->toBe([]);
});

it('runs the Rector sets for PHP 8.5, Laravel 13, type declarations, code quality and dead code', function (): void {
    $builder = require testkitConfig('rector.php');

    if (! is_object($builder)) {
        throw new UnexpectedValueException('The shared rector.php must return the Rector config builder.');
    }

    // Rector keeps the chosen sets in private state; reading it checks the effective config.
    $sets = new ReflectionProperty($builder, 'sets')->getValue($builder);

    if (! is_array($sets)) {
        throw new UnexpectedValueException('Rector no longer keeps its sets in an array; update this test.');
    }

    $sets = array_map(static fn (mixed $set): string|false => is_string($set) ? realpath($set) : false, $sets);
    $vendor = Phpstan::root().'/vendor';

    expect($sets)->not->toContain(false);
    expect($sets)->toEqualCanonicalizing([
        realpath($vendor.'/rector/rector/config/set/php-version-based.php'),
        realpath(LaravelLevelSetList::UP_TO_LARAVEL_130),
        realpath($vendor.'/rector/rector/config/set/type-declaration.php'),
        realpath($vendor.'/rector/rector/config/set/code-quality.php'),
        realpath($vendor.'/rector/rector/config/set/dead-code.php'),
    ]);
    expect(new ReflectionProperty($builder, 'pickedPhpSetsVersion')->getValue($builder))->toBe(80500);
});

it('formats with the Laravel preset and strict types', function (): void {
    $pint = json_decode((string) file_get_contents(testkitConfig('pint.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($pint)->toBeArray()
        ->toHaveKey('preset', 'laravel')
        ->toHaveKey('rules.declare_strict_types', true)
        ->toHaveKey('rules.strict_comparison', true)
        ->toHaveKey('rules.strict_param', true);
});
