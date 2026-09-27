<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Cli;

use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Illuminate\Contracts\Console\Kernel;

/*
 * cms:generate in the testbench application, pointed at a scratch root through cbox-cms.generators.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

const VALID_BLUEPRINT = <<<'YAML'
    blueprint: 1
    kind: type
    type_id: 0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b
    handle: page
    label: Page
    version: 1
    capabilities:
      history: full
      stages: draft-release
      localization: none
    fields:
      - handle: title
        label: Title
        description: The title of the page.
        type: text
        classification: public

    YAML;

/**
 * A scratch root with the blueprint in schema/page.yaml, set as cbox-cms.generators.root with the
 * schema root `schema` of app. Without a blueprint, the schema root does not exist.
 */
function generateRoot(?string $blueprint = VALID_BLUEPRINT): string
{
    $root = SchemaFixtures::scratch();

    if ($blueprint !== null) {
        SchemaFixtures::write($root.'/schema/page.yaml', $blueprint);
    }

    config()->set('cbox-cms.generators', [
        'root' => $root,
        'roots' => ['app' => 'schema'],
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

it('points at the workbench\'s schema root in the workbench', function (): void {
    expect(config('cbox-cms.generators'))->toBe([
        'root' => dirname(__DIR__, 4),
        'roots' => ['app' => 'workbench/schema'],
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
        ->and(SchemaFixtures::files($root))->toBe(['app/Cms/Generated/TypeHandle.php', 'resources/js/cms/generated/index.ts', 'schema/page.yaml']);
});

it('removes a stale file from a generated directory and says so', function (): void {
    $root = generateRoot();
    SchemaFixtures::write($root.'/app/Cms/Generated/Stale.php', "<?php\n");

    [$status, $output] = generateCommand();

    expect($status)->toBe(0)
        ->and($output)->toContain('removed: app/Cms/Generated/Stale.php')
        ->and(is_file($root.'/app/Cms/Generated/Stale.php'))->toBeFalse();
});

it('exits with 65, prints each problem with its code and writes nothing when a blueprint is invalid', function (): void {
    $root = generateRoot(str_replace(['handle: page', "fields:\n  - handle: title"], ['handle: Page', "fields:\n  - handle: Title"], VALID_BLUEPRINT));

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_SCHEMA)
        ->and($output)->toHaveCount(3)
        ->and($output[0])->toStartWith('[generate_schema_invalid] schema/page.yaml, /fields/0/handle: ')
        ->and($output[1])->toStartWith('[generate_schema_invalid] schema/page.yaml, /handle: ')
        ->and($output[2])->toBe('Nothing was generated, and the generated code was left as it was.')
        ->and(SchemaFixtures::files($root))->toBe(['schema/page.yaml']);
});

it('exits with 65 when two owners define the same type handle', function (): void {
    $root = generateRoot();
    SchemaFixtures::write($root.'/vendor/acme/shop/schema/page.yaml', str_replace('1c2d3e4f5a6b', 'aaaaaaaaaaaa', VALID_BLUEPRINT));
    config()->set('cbox-cms.generators.roots', ['app' => 'schema', 'acme' => 'vendor/acme/shop/schema']);

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_SCHEMA)
        ->and($output[0])->toBe('[generate_handle_collision] The type "page" of app (schema/page.yaml) and the type "page" of acme (vendor/acme/shop/schema/page.yaml) have the same handle. cms:generate names a type by its handle alone, so the handles of all owners must differ: rename one of the types.')
        ->and(SchemaFixtures::files($root))->toBe(['schema/page.yaml', 'vendor/acme/shop/schema/page.yaml']);
});

it('exits with 66 when a schema root is missing', function (): void {
    $root = generateRoot(null);

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_SCHEMA_MISSING)
        ->and($output[0])->toBe(sprintf('[generate_schema_missing] The schema root %s/schema of app does not exist or cannot be read. Create the directory, or remove the root.', $root))
        ->and(SchemaFixtures::files($root))->toBe([]);
});

it('generates the enum without cases and never from a schema root without blueprint files', function (): void {
    $root = generateRoot(null);
    mkdir($root.'/schema');
    SchemaFixtures::write($root.'/schema/README.md', "No blueprints yet.\n");

    [$status] = generateCommand();

    expect($status)->toBe(0)
        ->and((string) file_get_contents($root.'/app/Cms/Generated/TypeHandle.php'))->toContain("enum TypeHandle: string\n{\n    /**")
        ->and((string) file_get_contents($root.'/app/Cms/Generated/TypeHandle.php'))->toContain("        return [];\n")
        ->and((string) file_get_contents($root.'/resources/js/cms/generated/index.ts'))->toContain("export type TypeHandle = never;\n")
        ->and((string) file_get_contents($root.'/resources/js/cms/generated/index.ts'))->toContain("export type TypeFields = { [Handle in TypeHandle]: never };\n");
});

it('exits with 78 when the configuration is invalid', function (): void {
    generateRoot();
    config()->set('cbox-cms.generators.php_directory', '../outside/Generated');
    config()->set('cbox-cms.generators.php_namespace', 'app\cms');

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_CONFIG)
        ->and($output[0])->toContain('[generate_invalid_config] cbox-cms.generators.php_directory "../outside/Generated" is not a relative path')
        ->and($output[1])->toContain('[generate_invalid_config] cbox-cms.generators.php_namespace "app\cms" is not a PHP namespace');
});

it('exits with 78 when the schema roots are not a map from owner to directory', function (mixed $roots, string $problem): void {
    generateRoot();
    config()->set('cbox-cms.generators.roots', $roots);

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_CONFIG)
        ->and($output[0])->toContain('[generate_invalid_config] '.$problem);
})->with([
    'a list' => [['schema'], 'cbox-cms.generators.roots must map each owner to its schema directory'],
    'empty' => [[], 'cbox-cms.generators.roots must map each owner to its schema directory'],
    'an owner that is not a name' => [['App' => 'schema'], '"App" is not an owner.'],
    'a directory outside the root' => [['app' => '../schema'], 'The schema root "../schema" of app is not a relative path below its base'],
    'a directory that is not a string' => [['app' => ['schema']], 'cbox-cms.generators.roots.app must be a directory below the root'],
]);

it('exits with 78 when one schema root lies in another', function (): void {
    $root = generateRoot();
    mkdir($root.'/schema/acme');
    config()->set('cbox-cms.generators.roots', ['app' => 'schema', 'acme' => 'schema/acme']);

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_CONFIG)
        ->and($output[0])->toBe(sprintf('[generate_invalid_config] The schema root %1$s/schema/acme of acme lies in the schema root %1$s/schema of app, so its files would be read twice. Give each directory once.', $root));
});

it('exits with 70 when the configured directory is not a Generated directory', function (): void {
    $root = generateRoot();
    SchemaFixtures::write($root.'/app/Models/User.php', "<?php\n");
    config()->set('cbox-cms.generators.php_directory', 'app/Models');

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_OUTPUT)
        ->and($output[0])->toContain('[generate_invalid_output]')
        ->and(SchemaFixtures::files($root))->toBe(['app/Models/User.php', 'schema/page.yaml']);
});

it('exits with 73 when a generated file cannot be written', function (): void {
    $root = generateRoot();
    SchemaFixtures::write($root.'/resources/js/cms', "not a directory\n");

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_UNWRITABLE)
        ->and($output[0])->toStartWith('[generate_output_unwritable] ');
});

it('uses the application base path when root is null', function (): void {
    generateRoot();
    config()->set('cbox-cms.generators.root');
    config()->set('cbox-cms.generators.roots', ['app' => 'schema/does-not-exist']);

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_SCHEMA_MISSING)
        ->and($output[0])->toContain(base_path('schema/does-not-exist'));
});

it('reads the package default schema root of app, schema below the root', function (): void {
    expect(require dirname(__DIR__, 2).'/config/generators.php')->toMatchArray(['root' => null, 'roots' => ['app' => 'schema']]);
});

it('has an exit code for every error code', function (GenerateErrorCode $code): void {
    expect(GenerateCommand::exitCode($code))->toBe(match ($code) {
        GenerateErrorCode::InvalidConfig => 78,
        GenerateErrorCode::SchemaMissing => 66,
        GenerateErrorCode::InvalidOutput => 70,
        GenerateErrorCode::OutputUnwritable, GenerateErrorCode::SchemaUnwritable => 73,
        default => 65,
    });
})->with(GenerateErrorCode::cases());
