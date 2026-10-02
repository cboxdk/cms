<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Cli;

use Cbox\Cms\Generators\Cli\Console\GenerateCommand;
use Cbox\Cms\Generators\Cli\Console\SchemaEditorCommand;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Illuminate\Contracts\Console\Kernel;

/*
 * cms:schema:editor in the testbench application, pointed at a scratch root through
 * cbox-cms.generators and at the blueprint schema of the installed cboxdk/cms.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

const EDITOR_BLUEPRINT = <<<'YAML'
    # The page type, with a comment first.
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
      # A comment between the fields.
      - handle: title
        label: Title
        description: The title of the page.
        type: text
        classification: public

    YAML;

/**
 * A scratch root with the blueprint in schema/page.yaml, set as cbox-cms.generators.root with the
 * schema root `schema` of app.
 */
function editorRoot(string $blueprint = EDITOR_BLUEPRINT): string
{
    $root = SchemaFixtures::scratch();
    SchemaFixtures::write($root.'/schema/page.yaml', $blueprint);

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
function editorCommand(string $command = 'cms:schema:editor'): array
{
    $artisan = app(Kernel::class);
    $status = $artisan->call($command);

    return [$status, array_values(array_filter(array_map(trim(...), explode("\n", $artisan->output())), static fn (string $line): bool => $line !== ''))];
}

/**
 * The installed blueprint schema, resolved to the real file.
 */
function installedBlueprintSchema(): string
{
    return (string) realpath(dirname(__DIR__, 4).'/packages/contracts/resources/schemas/blueprint.v1.json');
}

/**
 * The path in the file's editor line, or null when its first line is not one.
 */
function editorPathOf(string $file): ?string
{
    $first = strtok((string) file_get_contents($file), "\n");

    return is_string($first) && str_starts_with($first, '# yaml-language-server: $schema=')
        ? substr($first, strlen('# yaml-language-server: $schema='))
        : null;
}

it('is registered', function (): void {
    expect(app(Kernel::class)->all())->toHaveKey('cms:schema:editor')
        ->and(app(Kernel::class)->all()['cms:schema:editor'])->toBeInstanceOf(SchemaEditorCommand::class);
});

it('adds the line with the path to the schema of the installed cboxdk/cms and keeps the rest of the file', function (): void {
    $root = editorRoot();

    [$status, $output] = editorCommand();
    $path = editorPathOf($root.'/schema/page.yaml');

    expect($status)->toBe(0)
        ->and($output)->toBe(['changed: schema/page.yaml', 'Checked 1 blueprint file: 1 changed, 0 unchanged.'])
        ->and($path)->toBeString()->toEndWith('/packages/contracts/resources/schemas/blueprint.v1.json')
        ->and(str_starts_with((string) $path, '../'))->toBeTrue()
        ->and(realpath($root.'/schema/'.$path))->toBe(installedBlueprintSchema())
        ->and(file_get_contents($root.'/schema/page.yaml'))->toBe('# yaml-language-server: $schema='.$path."\n".EDITOR_BLUEPRINT);
});

it('changes nothing the second time and does not write the file again', function (): void {
    $root = editorRoot();
    editorCommand();
    touch($root.'/schema/page.yaml', 1_000_000_000);
    $contents = file_get_contents($root.'/schema/page.yaml');

    [$status, $output] = editorCommand();
    clearstatcache();

    expect($status)->toBe(0)
        ->and($output)->toBe(['Checked 1 blueprint file: 0 changed, 1 unchanged.'])
        ->and(file_get_contents($root.'/schema/page.yaml'))->toBe($contents)
        ->and(filemtime($root.'/schema/page.yaml'))->toBe(1_000_000_000);
});

it('replaces a wrong path instead of adding a second line', function (): void {
    $root = editorRoot("# yaml-language-server: \$schema=../packages/contracts/resources/schemas/blueprint.v1.json\r\n".str_replace("\n", "\r\n", EDITOR_BLUEPRINT));

    [$status, $output] = editorCommand();
    $path = editorPathOf($root.'/schema/page.yaml');
    $contents = (string) file_get_contents($root.'/schema/page.yaml');

    expect($status)->toBe(0)
        ->and($output)->toBe(['changed: schema/page.yaml', 'Checked 1 blueprint file: 1 changed, 0 unchanged.'])
        ->and(substr_count($contents, 'yaml-language-server'))->toBe(1)
        ->and(realpath($root.'/schema/'.rtrim((string) $path, "\r")))->toBe(installedBlueprintSchema())
        ->and($contents)->toBe('# yaml-language-server: $schema='.rtrim((string) $path, "\r")."\r\n".str_replace("\n", "\r\n", EDITOR_BLUEPRINT));
});

it('gives a file deeper below the root a path from its own directory', function (): void {
    $root = editorRoot();
    SchemaFixtures::write($root.'/schema/shop/product.yaml', "blueprint: 1\n");

    [$status, $output] = editorCommand();

    expect($status)->toBe(0)
        ->and($output)->toBe(['changed: schema/page.yaml', 'changed: schema/shop/product.yaml', 'Checked 2 blueprint files: 2 changed, 0 unchanged.'])
        ->and(editorPathOf($root.'/schema/shop/product.yaml'))->toBe('../'.editorPathOf($root.'/schema/page.yaml'))
        ->and(realpath($root.'/schema/shop/'.editorPathOf($root.'/schema/shop/product.yaml')))->toBe(installedBlueprintSchema());
});

it('leaves files the reader still accepts', function (): void {
    $root = editorRoot();
    editorCommand();

    [$status, $output] = editorCommand('cms:generate');

    expect($status)->toBe(0)
        ->and($output)->toContain('Generated 44 files: 44 written, 0 unchanged, 0 stale removed.')
        ->and((string) file_get_contents($root.'/app/Cms/Generated/TypeHandle.php'))->toContain("    case AppPage = 'app:page';");
});

it('checks a root without blueprint files and changes nothing', function (): void {
    $root = editorRoot();
    unlink($root.'/schema/page.yaml');
    SchemaFixtures::write($root.'/schema/README.md', "No blueprints yet.\n");

    [$status, $output] = editorCommand();

    expect($status)->toBe(0)
        ->and($output)->toBe(['Checked 0 blueprint files: 0 changed, 0 unchanged.'])
        ->and(file_get_contents($root.'/schema/README.md'))->toBe("No blueprints yet.\n");
});

it('leaves the files of a schema root below vendor/ untouched and names the root', function (): void {
    $root = editorRoot();
    SchemaFixtures::write($root.'/vendor/acme/shop/schema/product.yaml', "blueprint: 1\n");
    SchemaFixtures::write($root.'/vendor/acme/shop/schema/wrong.yaml', "# yaml-language-server: \$schema=../wrong.json\nblueprint: 1\n");
    touch($root.'/vendor/acme/shop/schema/product.yaml', 1_000_000_000);
    config()->set('cbox-cms.generators.roots', ['app' => 'schema', 'acme' => 'vendor/acme/shop/schema', 'gone' => 'vendor/gone/schema']);

    [$status, $output] = editorCommand();
    clearstatcache();

    expect($status)->toBe(0)
        ->and($output)->toBe([
            'skipped: vendor/acme/shop/schema, a schema root below vendor/ whose files Composer installs',
            'skipped: vendor/gone/schema, a schema root below vendor/ whose files Composer installs',
            'changed: schema/page.yaml',
            'Checked 1 blueprint file: 1 changed, 0 unchanged, 2 schema roots below vendor/ skipped.',
        ])
        ->and(file_get_contents($root.'/vendor/acme/shop/schema/product.yaml'))->toBe("blueprint: 1\n")
        ->and(file_get_contents($root.'/vendor/acme/shop/schema/wrong.yaml'))->toBe("# yaml-language-server: \$schema=../wrong.json\nblueprint: 1\n")
        ->and(filemtime($root.'/vendor/acme/shop/schema/product.yaml'))->toBe(1_000_000_000)
        ->and(editorPathOf($root.'/schema/page.yaml'))->toBeString();
});

it('names the skipped root in the summary when a file fails', function (): void {
    $root = editorRoot();
    SchemaFixtures::write($root.'/vendor/acme/shop/schema/product.yaml', "blueprint: 1\n");
    config()->set('cbox-cms.generators.roots', ['app' => 'schema', 'acme' => 'vendor/acme/shop/schema']);
    chmod($root.'/schema/page.yaml', 0o444);

    [$status, $output] = editorCommand();

    expect($status)->toBe(GenerateCommand::EXIT_UNWRITABLE)
        ->and($output)->toBe([
            'skipped: vendor/acme/shop/schema, a schema root below vendor/ whose files Composer installs',
            '[generate_schema_unwritable] schema/page.yaml cannot be written: the file is read-only. Its editor line was not changed; make it writable and run cms:schema:editor again.',
            'Checked 1 blueprint file: 0 changed, 0 unchanged, 1 schema root below vendor/ skipped, 1 failed.',
        ])
        ->and(file_get_contents($root.'/vendor/acme/shop/schema/product.yaml'))->toBe("blueprint: 1\n");
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores file permissions');

it('exits with 73 when a file cannot be written, and still edits the others', function (): void {
    $root = editorRoot();
    SchemaFixtures::write($root.'/schema/other.yaml', "blueprint: 1\n");
    chmod($root.'/schema/page.yaml', 0o444);

    [$status, $output] = editorCommand();

    expect($status)->toBe(GenerateCommand::EXIT_UNWRITABLE)
        ->and($output)->toBe([
            'changed: schema/other.yaml',
            '[generate_schema_unwritable] schema/page.yaml cannot be written: the file is read-only. Its editor line was not changed; make it writable and run cms:schema:editor again.',
            'Checked 2 blueprint files: 1 changed, 0 unchanged, 1 failed.',
        ])
        ->and(file_get_contents($root.'/schema/page.yaml'))->toBe(EDITOR_BLUEPRINT)
        ->and(editorPathOf($root.'/schema/other.yaml'))->toBeString();
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores file permissions');

it('exits with 66 when a file cannot be read', function (): void {
    $root = editorRoot();
    chmod($root.'/schema/page.yaml', 0o000);

    [$status, $output] = editorCommand();

    expect($status)->toBe(GenerateCommand::EXIT_SCHEMA_MISSING)
        ->and($output[0])->toBe('[generate_schema_missing] schema/page.yaml cannot be read. Check its permissions.');
})->skip(fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0, 'root ignores file permissions');

it('exits with 66 and changes nothing when a schema root is missing', function (): void {
    $root = editorRoot();
    config()->set('cbox-cms.generators.roots', ['app' => 'schema', 'shop' => 'modules/shop/schema']);

    [$status, $output] = editorCommand();

    expect($status)->toBe(GenerateCommand::EXIT_SCHEMA_MISSING)
        ->and($output)->toBe([
            sprintf('[generate_schema_missing] The schema root %s/modules/shop/schema of shop does not exist or cannot be read. Create the directory, or remove the root.', $root),
            'No blueprint file was changed.',
        ])
        ->and(file_get_contents($root.'/schema/page.yaml'))->toBe(EDITOR_BLUEPRINT);
});

it('exits with 78 and changes nothing when the configuration is invalid', function (string $key, mixed $value, string $problem): void {
    $root = editorRoot();
    config()->set('cbox-cms.generators.'.$key, $value);

    [$status, $output] = editorCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_CONFIG)
        ->and($output[0])->toStartWith('[generate_invalid_config] ')->toContain($problem)
        ->and($output[count($output) - 1])->toBe('No blueprint file was changed.')
        ->and(file_get_contents($root.'/schema/page.yaml'))->toBe(EDITOR_BLUEPRINT);
})->with([
    'roots that are a list' => ['roots', ['schema'], 'cbox-cms.generators.roots must map each owner to its schema directory'],
    'a root outside the root' => ['roots', ['app' => '../schema'], 'The schema root "../schema" of app is not a relative path below its base'],
    'two owners with one root' => ['roots', ['app' => 'schema', 'acme' => 'schema'], '/schema of app lies in the schema root '],
    'a root that is not absolute' => ['root', 'relative/path', 'The base "relative/path" of the schema root of app is not an absolute path.'],
]);

it('exits with 78 and changes nothing when the installed contracts have no blueprint schema', function (): void {
    $root = editorRoot();
    app()->instance(BlueprintSchemaFile::class, new BlueprintSchemaFile($root.'/vendor/cboxdk/cms/packages/contracts/resources/schemas/blueprint.v1.json'));

    [$status, $output] = editorCommand();

    expect($status)->toBe(GenerateCommand::EXIT_INVALID_CONFIG)
        ->and($output)->toBe([
            sprintf('[generate_invalid_config] The blueprint schema %s/vendor/cboxdk/cms/packages/contracts/resources/schemas/blueprint.v1.json does not exist. Reinstall cboxdk/cms with `composer install`.', $root),
            'No blueprint file was changed.',
        ])
        ->and(file_get_contents($root.'/schema/page.yaml'))->toBe(EDITOR_BLUEPRINT);
});

it('points editors at the path the reader validates against, through the root where cboxdk/cms is the root package', function (): void {
    expect(realpath(new BlueprintSchemaFile()->editorPath()))->toBe(realpath(new BlueprintSchemaFile()->path()))
        ->and(new BlueprintSchemaFile()->editorPath())->toBe(installedBlueprintSchema())
        ->and(new BlueprintSchemaFile()->editorPath())->not->toContain('/vendor/');
});

it('points editors through the root after a tool registers another Composer root package first', function (): void {
    // Rector's bundled autoloader adds rector/rector-src as a Composer root package, which
    // InstalledVersions::getRootPackage() then returns; cboxdk/cms is still a root package.
    require_once dirname(__DIR__, 4).'/vendor/rector/rector/vendor/autoload.php';

    expect(new BlueprintSchemaFile()->editorPath())->toBe(installedBlueprintSchema());
});

it('keeps the directory of an installed cboxdk/cms as Composer installed it and resolves the directories above it', function (): void {
    $root = SchemaFixtures::scratch();
    mkdir($root.'/real/cboxdk/cms', 0o777, true);
    mkdir($root.'/real/composer', 0o777, true);
    mkdir($root.'/app', 0o777, true);
    symlink($root.'/real', $root.'/app/vendor');
    mkdir($root.'/store/cms', 0o777, true);
    symlink($root.'/store/cms', $root.'/real/cboxdk/cms-link');
    $real = (string) realpath($root);

    expect(BlueprintSchemaFile::editorDirectory($root.'/app/vendor/composer/../cboxdk/cms', false))->toBe($real.'/real/cboxdk/cms')
        ->and(BlueprintSchemaFile::editorDirectory($root.'/app/vendor/cboxdk/cms-link', false))->toBe($real.'/real/cboxdk/cms-link')
        ->and(BlueprintSchemaFile::editorDirectory($root.'/store/cms/../../real/composer/../..', true))->toBe($real)
        ->and(BlueprintSchemaFile::editorDirectory($root.'/missing/cboxdk/cms', false))->toBe($root.'/missing/cboxdk/cms')
        ->and(BlueprintSchemaFile::editorDirectory($root.'/missing', true))->toBe($root.'/missing');
});
