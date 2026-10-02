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
        'migrations_directory' => 'database/migrations/cms',
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

it('points at the workbench\'s schema root and the fixture addon\'s in the workbench', function (): void {
    expect(config('cbox-cms.generators'))->toBe([
        'root' => dirname(__DIR__, 4),
        'roots' => ['app' => 'workbench/schema', 'fixtureaddon' => 'workbench/addons/fixtureaddon/schema'],
        'php_directory' => 'workbench/app/Cms/Generated',
        'php_namespace' => 'Workbench\App\Cms\Generated',
        'typescript_directory' => 'workbench/resources/js/cms/generated',
        'migrations_directory' => 'workbench/database/migrations/cms',
    ]);
});

it('writes the PHP enum, the record DTO and codec, the validator, the TypeScript union and the type table\'s migration and schema lock, and a second run changes nothing', function (): void {
    $root = generateRoot();

    [$first, $firstOutput] = generateCommand();
    $hashes = array_map(static fn (string $file): string => (string) hash_file('sha256', $root.'/'.$file), SchemaFixtures::files($root));
    [$second, $secondOutput] = generateCommand();

    expect($first)->toBe(0)
        ->and($firstOutput)->toBe([
            'written: app/Cms/Generated/Boundary/AppPageCodecV1.php',
            'written: app/Cms/Generated/Domain/Dto/AppPageV1.php',
            'written: app/Cms/Generated/GeneratedRecordCodecs.php',
            'written: app/Cms/Generated/GeneratedTypeCatalog.php',
            'written: app/Cms/Generated/GeneratedTypeValidators.php',
            'written: app/Cms/Generated/GeneratedTypesServiceProvider.php',
            'written: app/Cms/Generated/QueryBuilders/AppPage/AppPageFilterField.php',
            'written: app/Cms/Generated/QueryBuilders/AppPage/AppPageQuery.php',
            'written: app/Cms/Generated/QueryBuilders/AppPage/AppPageSortField.php',
            'written: app/Cms/Generated/Records/AppPage/AppPage.php',
            'written: app/Cms/Generated/Records/AppPage/AppPageFactory.php',
            'written: app/Cms/Generated/Records/AppPage/AppPageRecord.php',
            'written: app/Cms/Generated/Records/AppPage/AppPageRecordFactory.php',
            'written: app/Cms/Generated/TypeHandle.php',
            'written: app/Cms/Generated/Validators/AppPageValidator.php',
            'written: database/migrations/cms/app__page.lock',
            'written: database/migrations/cms/app__page_0001_create.php',
            'written: resources/js/cms/generated/index.ts',
            'written: resources/js/cms/generated/protocol/ActivateActorV1.ts',
            'written: resources/js/cms/generated/protocol/AssignGrantV1.ts',
            'written: resources/js/cms/generated/protocol/CreateEntryV1.ts',
            'written: resources/js/cms/generated/protocol/CreatePlacementV1.ts',
            'written: resources/js/cms/generated/protocol/CreateRoleV1.ts',
            'written: resources/js/cms/generated/protocol/DeactivateActorV1.ts',
            'written: resources/js/cms/generated/protocol/DeliveryExplanationV1.ts',
            'written: resources/js/cms/generated/protocol/DeliveryFragmentV1.ts',
            'written: resources/js/cms/generated/protocol/DeliveryV1.ts',
            'written: resources/js/cms/generated/protocol/EnvelopeV1.ts',
            'written: resources/js/cms/generated/protocol/ExplainedPathV1.ts',
            'written: resources/js/cms/generated/protocol/PathExplanationV1.ts',
            'written: resources/js/cms/generated/protocol/ProblemV1.ts',
            'written: resources/js/cms/generated/protocol/PublishEntryV1.ts',
            'written: resources/js/cms/generated/protocol/ReceiptV1.ts',
            'written: resources/js/cms/generated/protocol/RegisterActorV1.ts',
            'written: resources/js/cms/generated/protocol/ReleaseVariantV1.ts',
            'written: resources/js/cms/generated/protocol/ResolvePathV1.ts',
            'written: resources/js/cms/generated/protocol/ResolvedPathV1.ts',
            'written: resources/js/cms/generated/protocol/ReviseEntryV1.ts',
            'written: resources/js/cms/generated/protocol/RevokeGrantV1.ts',
            'written: resources/js/cms/generated/protocol/SetPlacementWindowV1.ts',
            'written: resources/js/cms/generated/protocol/SetRolePermissionsV1.ts',
            'written: resources/js/cms/generated/protocol/UnpublishEntryV1.ts',
            'written: resources/js/cms/generated/records/AppPageV1.ts',
            'written: resources/js/cms/generated/validation.ts',
            'Generated 44 files: 44 written, 0 unchanged, 0 stale removed.',
        ])
        ->and($second)->toBe(0)
        ->and($secondOutput)->toBe(['Generated 44 files: 0 written, 44 unchanged, 0 stale removed.'])
        ->and(array_map(static fn (string $file): string => (string) hash_file('sha256', $root.'/'.$file), SchemaFixtures::files($root)))->toBe($hashes)
        ->and(SchemaFixtures::files($root))->toBe([
            'app/Cms/Generated/Boundary/AppPageCodecV1.php',
            'app/Cms/Generated/Domain/Dto/AppPageV1.php',
            'app/Cms/Generated/GeneratedRecordCodecs.php',
            'app/Cms/Generated/GeneratedTypeCatalog.php',
            'app/Cms/Generated/GeneratedTypeValidators.php',
            'app/Cms/Generated/GeneratedTypesServiceProvider.php',
            'app/Cms/Generated/QueryBuilders/AppPage/AppPageFilterField.php',
            'app/Cms/Generated/QueryBuilders/AppPage/AppPageQuery.php',
            'app/Cms/Generated/QueryBuilders/AppPage/AppPageSortField.php',
            'app/Cms/Generated/Records/AppPage/AppPage.php',
            'app/Cms/Generated/Records/AppPage/AppPageFactory.php',
            'app/Cms/Generated/Records/AppPage/AppPageRecord.php',
            'app/Cms/Generated/Records/AppPage/AppPageRecordFactory.php',
            'app/Cms/Generated/TypeHandle.php',
            'app/Cms/Generated/Validators/AppPageValidator.php',
            'database/migrations/cms/app__page.lock',
            'database/migrations/cms/app__page_0001_create.php',
            'resources/js/cms/generated/index.ts',
            'resources/js/cms/generated/protocol/ActivateActorV1.ts',
            'resources/js/cms/generated/protocol/AssignGrantV1.ts',
            'resources/js/cms/generated/protocol/CreateEntryV1.ts',
            'resources/js/cms/generated/protocol/CreatePlacementV1.ts',
            'resources/js/cms/generated/protocol/CreateRoleV1.ts',
            'resources/js/cms/generated/protocol/DeactivateActorV1.ts',
            'resources/js/cms/generated/protocol/DeliveryExplanationV1.ts',
            'resources/js/cms/generated/protocol/DeliveryFragmentV1.ts',
            'resources/js/cms/generated/protocol/DeliveryV1.ts',
            'resources/js/cms/generated/protocol/EnvelopeV1.ts',
            'resources/js/cms/generated/protocol/ExplainedPathV1.ts',
            'resources/js/cms/generated/protocol/PathExplanationV1.ts',
            'resources/js/cms/generated/protocol/ProblemV1.ts',
            'resources/js/cms/generated/protocol/PublishEntryV1.ts',
            'resources/js/cms/generated/protocol/ReceiptV1.ts',
            'resources/js/cms/generated/protocol/RegisterActorV1.ts',
            'resources/js/cms/generated/protocol/ReleaseVariantV1.ts',
            'resources/js/cms/generated/protocol/ResolvePathV1.ts',
            'resources/js/cms/generated/protocol/ResolvedPathV1.ts',
            'resources/js/cms/generated/protocol/ReviseEntryV1.ts',
            'resources/js/cms/generated/protocol/RevokeGrantV1.ts',
            'resources/js/cms/generated/protocol/SetPlacementWindowV1.ts',
            'resources/js/cms/generated/protocol/SetRolePermissionsV1.ts',
            'resources/js/cms/generated/protocol/UnpublishEntryV1.ts',
            'resources/js/cms/generated/records/AppPageV1.ts',
            'resources/js/cms/generated/validation.ts',
            'schema/page.yaml',
        ]);
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

it('generates when a module release adds a type with the handle of an app type, and keeps the app\'s names', function (): void {
    $root = generateRoot();
    mkdir($root.'/vendor/acme/shop/schema', 0o777, true);
    config()->set('cbox-cms.generators.roots', ['app' => 'schema', 'acme' => 'vendor/acme/shop/schema']);

    [$before] = generateCommand();
    $php = (string) file_get_contents($root.'/app/Cms/Generated/TypeHandle.php');
    $typeScript = (string) file_get_contents($root.'/resources/js/cms/generated/index.ts');

    SchemaFixtures::write($root.'/vendor/acme/shop/schema/page.yaml', str_replace('1c2d3e4f5a6b', 'aaaaaaaaaaaa', VALID_BLUEPRINT));

    [$after, $output] = generateCommand();

    expect($before)->toBe(0)
        ->and($php)->toContain("    case AppPage = 'app:page';\n")
        ->and($after)->toBe(0)
        ->and($output)->toBe([
            'written: app/Cms/Generated/Boundary/AcmePageCodecV1.php',
            'written: app/Cms/Generated/Domain/Dto/AcmePageV1.php',
            'written: app/Cms/Generated/GeneratedRecordCodecs.php',
            'written: app/Cms/Generated/GeneratedTypeCatalog.php',
            'written: app/Cms/Generated/GeneratedTypeValidators.php',
            'written: app/Cms/Generated/GeneratedTypesServiceProvider.php',
            'written: app/Cms/Generated/QueryBuilders/AcmePage/AcmePageFilterField.php',
            'written: app/Cms/Generated/QueryBuilders/AcmePage/AcmePageQuery.php',
            'written: app/Cms/Generated/QueryBuilders/AcmePage/AcmePageSortField.php',
            'written: app/Cms/Generated/Records/AcmePage/AcmePage.php',
            'written: app/Cms/Generated/Records/AcmePage/AcmePageFactory.php',
            'written: app/Cms/Generated/Records/AcmePage/AcmePageRecord.php',
            'written: app/Cms/Generated/Records/AcmePage/AcmePageRecordFactory.php',
            'written: app/Cms/Generated/TypeHandle.php',
            'written: app/Cms/Generated/Validators/AcmePageValidator.php',
            'written: database/migrations/cms/acme__page.lock',
            'written: database/migrations/cms/acme__page_0001_create.php',
            'written: resources/js/cms/generated/index.ts',
            'written: resources/js/cms/generated/records/AcmePageV1.ts',
            'Generated 57 files: 19 written, 38 unchanged, 0 stale removed.',
        ])
        ->and(is_file($root.'/app/Cms/Generated/Validators/AppPageValidator.php'))->toBeTrue()
        ->and((string) file_get_contents($root.'/app/Cms/Generated/TypeHandle.php'))->toContain("    case AcmePage = 'acme:page';\n    case AppPage = 'app:page';\n")
        ->and($typeScript)->toContain("export type TypeHandle = 'app:page';\n")
        ->and((string) file_get_contents($root.'/resources/js/cms/generated/index.ts'))->toContain("export type TypeHandle = 'acme:page' | 'app:page';\n");
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

it('exits with 78 when the migrations directory does not end in migrations/cms or is missing', function (mixed $directory, string $problem): void {
    generateRoot();
    config()->set('cbox-cms.generators.migrations_directory', $directory);

    [$status, $output] = generateCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_CONFIG)
        ->and($output[0])->toContain('[generate_invalid_config] '.$problem);
})->with([
    'the application\'s migrations' => ['database/migrations', 'cbox-cms.generators.migrations_directory "database/migrations" does not end in "migrations/cms"'],
    'another name' => ['database/migrations/types', 'cbox-cms.generators.migrations_directory "database/migrations/types" does not end in "migrations/cms"'],
    'a path outside the root' => ['../migrations/cms', 'cbox-cms.generators.migrations_directory "../migrations/cms" is not a relative path'],
    'not a string' => [null, 'cbox-cms.generators.migrations_directory must be a string.'],
]);

it('takes a migrations directory that is migrations/cms itself', function (): void {
    $root = generateRoot();
    config()->set('cbox-cms.generators.migrations_directory', 'migrations/cms');

    [$status] = generateCommand();

    expect($status)->toBe(0)
        ->and(is_file($root.'/migrations/cms/app__page_0001_create.php'))->toBeTrue()
        ->and((string) file_get_contents($root.'/app/Cms/Generated/GeneratedTypesServiceProvider.php'))->toContain("\$this->loadMigrationsFrom(__DIR__.'/../../../migrations/cms');");
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
