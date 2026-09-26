<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Cli;

use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Illuminate\Contracts\Console\Kernel;

/*
 * cms:generate in the testbench application, pointed at a scratch root through cms.generators.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

const VALID_SCHEMA = <<<'YAML'
    format: m0-provisional
    types:
      - handle: page
        label: Page
        fields:
          - handle: title
            type: text

    YAML;

/**
 * A scratch root with the schema, set as cms.generators.root.
 */
function generateRoot(?string $schema = VALID_SCHEMA): string
{
    $root = SchemaFixtures::scratch();

    if ($schema !== null) {
        SchemaFixtures::write($root.'/schema/fixture.yaml', $schema);
    }

    config()->set('cms.generators', [
        'root' => $root,
        'schema' => 'schema/fixture.yaml',
        'php_directory' => 'app/Cms/Generated',
        'php_namespace' => 'App\Cms\Generated',
        'typescript_directory' => 'resources/js/cms/generated',
    ]);

    return $root;
}

/**
 * @return array{int, list<string>}
 */
function generateCommand(): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call('cms:generate');

    return [$status, array_values(array_filter(array_map(trim(...), explode("\n", $artisan->output())), static fn (string $line): bool => $line !== ''))];
}

it('is registered', function (): void {
    expect(app(Kernel::class)->all())->toHaveKey('cms:generate')
        ->and(app(Kernel::class)->all()['cms:generate'])->toBeInstanceOf(GenerateCommand::class);
});

it('points at the workbench fixture schema in the workbench', function (): void {
    expect(config('cms.generators'))->toBe([
        'root' => dirname(__DIR__, 4),
        'schema' => 'workbench/schema/fixture.yaml',
        'php_directory' => 'workbench/app/Cms/Generated',
        'php_namespace' => 'Workbench\App\Cms\Generated',
        'typescript_directory' => 'workbench/resources/js/cms/generated',
    ]);
});

it('writes the PHP enum and the TypeScript union, and a second run changes nothing', function (): void {
    $root = generateRoot();

    [$first, $firstOutput] = generateCommand();
    $hashes = array_map(static fn (string $file): string => (string) hash_file('sha256', $root.'/'.$file), SchemaFixtures::files($root));
    [$second, $secondOutput] = generateCommand();

    expect($first)->toBe(0)
        ->and($firstOutput)->toBe([
            'written: app/Cms/Generated/TypeHandle.php',
            'written: resources/js/cms/generated/index.ts',
            'Generated 2 files: 2 written, 0 unchanged, 0 stale removed.',
        ])
        ->and($second)->toBe(0)
        ->and($secondOutput)->toBe(['Generated 2 files: 0 written, 2 unchanged, 0 stale removed.'])
        ->and(array_map(static fn (string $file): string => (string) hash_file('sha256', $root.'/'.$file), SchemaFixtures::files($root)))->toBe($hashes)
        ->and(SchemaFixtures::files($root))->toBe(['app/Cms/Generated/TypeHandle.php', 'resources/js/cms/generated/index.ts', 'schema/fixture.yaml']);
});

it('removes a stale file from a generated directory and says so', function (): void {
    $root = generateRoot();
    SchemaFixtures::write($root.'/app/Cms/Generated/Stale.php', "<?php\n");

    [$status, $output] = generateCommand();

    expect($status)->toBe(0)
        ->and($output)->toContain('removed: app/Cms/Generated/Stale.php')
        ->and(is_file($root.'/app/Cms/Generated/Stale.php'))->toBeFalse();
});

it('exits with 65, prints each problem with its code and writes nothing when the schema is invalid', function (): void {
    $root = generateRoot("format: m0-provisional\ntypes:\n  - handle: Page\n    label: Page\n    fields: []\n");

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_SCHEMA)
        ->and($output)->toHaveCount(3)
        ->and($output[0])->toStartWith('[generate_schema_invalid] '.$root.'/schema/fixture.yaml, types[0].fields: must be a list')
        ->and($output[1])->toContain('types[0].handle: "Page" is not a handle')
        ->and($output[2])->toBe('Nothing was generated, and the generated code was left as it was.')
        ->and(SchemaFixtures::files($root))->toBe(['schema/fixture.yaml']);
});

it('exits with 66 when the schema file is missing', function (): void {
    generateRoot(null);

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_SCHEMA_MISSING)
        ->and($output[0])->toStartWith('[generate_schema_missing] ');
});

it('exits with 78 when the configuration is invalid', function (): void {
    generateRoot();
    config()->set('cms.generators.php_directory', '../outside/Generated');
    config()->set('cms.generators.php_namespace', 'app\cms');

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_CONFIG)
        ->and($output[0])->toContain('[generate_invalid_config] cms.generators.php_directory "../outside/Generated" is not a relative path')
        ->and($output[1])->toContain('[generate_invalid_config] cms.generators.php_namespace "app\cms" is not a PHP namespace');
});

it('exits with 70 when the configured directory is not a Generated directory', function (): void {
    $root = generateRoot();
    SchemaFixtures::write($root.'/app/Models/User.php', "<?php\n");
    config()->set('cms.generators.php_directory', 'app/Models');

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_OUTPUT)
        ->and($output[0])->toContain('[generate_invalid_output]')
        ->and(SchemaFixtures::files($root))->toBe(['app/Models/User.php', 'schema/fixture.yaml']);
});

it('uses the application base path when root is null', function (): void {
    generateRoot();
    config()->set('cms.generators.root');
    config()->set('cms.generators.schema', 'schema/does-not-exist.yaml');

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_SCHEMA_MISSING)
        ->and($output[0])->toContain(base_path('schema/does-not-exist.yaml'));
});

it('has an exit code for every error code', function (GenerateErrorCode $code): void {
    expect(GenerateCommand::exitCode($code))->toBeGreaterThan(0);
})->with(GenerateErrorCode::cases());
