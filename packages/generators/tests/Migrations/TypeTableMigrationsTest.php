<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Migrations;

use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\FieldDescriptor;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeDescriptor;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeTableMigrations;
use Cbox\Cms\Generators\Migrations\Boundary\TypeTableLockJson;
use Cbox\Cms\Generators\Migrations\Domain\MigrationSource;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\Migrations\Fakes\FakeSchemaLocks;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\Phpstan;
use Symfony\Component\Process\Process;

/*
 * The migrations of the type tables (PRD 4.1, 4.2, 11.6, 11.12): the comprehensive example's DDL
 * and migration are the committed golden files, a run over the locks it wrote writes the same
 * bytes, a new optional field adds one add_columns migration, and the migrations pass Pint, Rector
 * and PHPStan level 10 unchanged.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * What the generator writes for the schema over the locks the fake holds, path to contents.
 *
 * @return array<string, string>
 */
function migrationFiles(CompiledSchema $schema, FakeSchemaLocks $locks): array
{
    $files = [];

    foreach (new TypeTableMigrations($locks, TypeTableLockJson::encode(...))->generate($schema, SchemaFixtures::target()) as $file) {
        $files[$file->path] = $file->contents;
    }

    return $files;
}

/**
 * The fake with the files of a run, as cms:generate would have written them below the root.
 *
 * @param  array<string, string>  $files
 */
function committedLocks(array $files): FakeSchemaLocks
{
    $locks = new FakeSchemaLocks;

    foreach ($files as $path => $contents) {
        $locks->put(SchemaFixtures::ROOT.'/'.$path, $contents);
    }

    return $locks;
}

it('writes the committed golden DDL of the comprehensive example', function (): void {
    expect(ComprehensiveExample::ddl())->toBe((string) file_get_contents(ComprehensiveExample::GOLDEN_DDL), 'The DDL differs from '.ComprehensiveExample::GOLDEN_DDL.'. Review the difference; when it is intended, write the new DDL there.');
});

it('writes the committed golden migration and schema lock of the comprehensive example', function (): void {
    $generated = ComprehensiveExample::migrations();

    expect(array_keys($generated))->toBe(['migrations/cms/shop__product.lock', 'migrations/cms/shop__product_0001_create.php'])
        ->and($generated)->toBe(ComprehensiveExample::goldenMigrations(), 'The migrations differ from the golden files in '.ComprehensiveExample::DIRECTORY.'/'.ComprehensiveExample::MIGRATIONS_DIRECTORY.'. Review the difference; when it is intended, write the new files there.')
        ->and($generated['migrations/cms/shop__product_0001_create.php'])->toContain(implode("\n", array_map(
            static fn (string $line): string => '            '.$line,
            explode("\n", explode(";\n\n", substr(ComprehensiveExample::ddl(), (int) strpos(ComprehensiveExample::ddl(), 'create table')))[0]),
        )));
});

it('owns the target\'s migrations directory, and writes the locks and migrations there', function (): void {
    $generator = new TypeTableMigrations(new FakeSchemaLocks, TypeTableLockJson::encode(...));

    expect($generator->directory(SchemaFixtures::target()))->toBe('database/migrations/cms')
        ->and(array_keys(migrationFiles(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()]), new FakeSchemaLocks)))->toBe([
            'database/migrations/cms/app__note.lock',
            'database/migrations/cms/app__note_0001_create.php',
        ]);
});

it('writes the same bytes over the locks it wrote', function (): void {
    $schema = MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()]);
    $first = migrationFiles($schema, new FakeSchemaLocks);

    expect(migrationFiles($schema, committedLocks($first)))->toBe($first);
});

it('writes one add_columns migration for a new optional field of a type that has a table', function (): void {
    $first = migrationFiles(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()]), new FakeSchemaLocks);
    $zone = <<<'YAML'
          - handle: zone
            label: Zone
            description: The zone.
            type: text
            classification: public
            filterable: true

        YAML;

    $second = migrationFiles(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note(MigrationFixtures::TITLE.MigrationFixtures::RATING.$zone)]), committedLocks($first));
    $added = $second['database/migrations/cms/app__note_0002_add_columns.php'] ?? '';

    expect(array_keys($second))->toBe([
        'database/migrations/cms/app__note.lock',
        'database/migrations/cms/app__note_0001_create.php',
        'database/migrations/cms/app__note_0002_add_columns.php',
    ])
        ->and($second['database/migrations/cms/app__note_0001_create.php'])->toBe($first['database/migrations/cms/app__note_0001_create.php'])
        ->and(TypeTableLockJson::decode('database/migrations/cms/app__note.lock', $second['database/migrations/cms/app__note.lock'])->steps)->toBe(2)
        ->and($added)->toContain(implode("\n", [
            '        try {',
            "            \$connection->statement(<<<'SQL'",
            '                alter table "app__note"',
            '                    add column if not exists "zone" text',
            '                        check (char_length("zone") <= 255)',
            '                SQL);',
            "            \$connection->statement(<<<'SQL'",
            '                drop index concurrently if exists "app__note__zone"',
            '                SQL);',
            "            \$connection->statement(<<<'SQL'",
            '                create index concurrently "app__note__zone" on "app__note" (cms_stage, cms_locale, "zone", cms_entry_id)',
            '                SQL);',
            '        } finally {',
        ]))
        ->and($added)->toContain('    public $withinTransaction = false;')
        ->and($added)->toContain("\$connection->statement(\"set lock_timeout = '2s'\");")
        ->and($added)->toContain("            \$connection->statement('reset lock_timeout');")
        ->and($added)->toContain("            alter table \"app__note\"\n                drop column if exists \"zone\"\n")
        ->and(substr_count($added, 'add column'))->toBe(1)
        ->and(MigrationSource::name(TypeTableLockJson::decode('database/migrations/cms/app__note.lock', $second['database/migrations/cms/app__note.lock']), 2))->toBe('app__note_0002_add_columns');
});

it('refuses to remove a field and writes nothing', function (): void {
    $first = migrationFiles(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()]), new FakeSchemaLocks);

    expect(MigrationFixtures::codes(static fn (): array => migrationFiles(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note(MigrationFixtures::TITLE)]), committedLocks($first))))
        ->toBe([GenerateErrorCode::FieldRemoved]);
});

it('refuses a field type it has no mapping for', function (): void {
    $type = MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()])->types[0];
    $field = $type->fields[0];
    $unknown = new FieldDescriptor($field->column, $field->handle, $field->namespace, $field->owner, 'acme:colour', $field->label, $field->description, $field->required, $field->classification, $field->agents, $field->filterable, $field->sortable, $field->encrypted, $field->php, $field->typeScript, $field->validation, $field->choices, $field->fields, $field->location);
    $changed = new TypeDescriptor($type->typeId, $type->owner, $type->handle, $type->label, $type->description, $type->version, $type->capabilities, $type->extensions, [$unknown], $type->location);
    $failed = MigrationFixtures::failure(static fn (): array => new TypeTableMigrations(new FakeSchemaLocks, TypeTableLockJson::encode(...))->generate(new CompiledSchema([$changed]), SchemaFixtures::target()));

    expect($failed->codes())->toBe([GenerateErrorCode::InvalidOutput])
        ->and($failed->getMessage())->toContain(TypeTableMigrations::class.' has no mapping for the field type "acme:colour"');
});

it('writes migrations that Pint, Rector and PHPStan accept unchanged', function (): void {
    $first = migrationFiles(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note()]), new FakeSchemaLocks);
    $zone = <<<'YAML'
          - handle: zone
            label: Zone
            description: The zone.
            type: text
            classification: public
            sortable: true

        YAML;
    $files = migrationFiles(MigrationFixtures::compile(['note.yaml' => MigrationFixtures::note(MigrationFixtures::TITLE.MigrationFixtures::RATING.$zone)]), committedLocks($first));
    $directory = SchemaFixtures::scratch();
    $paths = [];

    foreach ($files as $path => $contents) {
        if (str_ends_with($path, '.php')) {
            $paths[] = $directory.'/'.basename($path);
            file_put_contents($directory.'/'.basename($path), $contents);
        }
    }

    $pint = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/pint.php', '--test', '--config='.Phpstan::root().'/pint.json', ...$paths], Phpstan::root());
    $pint->run();
    $rector = new Process([Phpstan::root().'/vendor/bin/rector', 'process', '--dry-run', '--no-progress-bar', ...$paths], Phpstan::root());
    $rector->run();
    $analysis = Phpstan::analyse($directory);

    expect($paths)->toHaveCount(2)
        ->and($pint->getExitCode())->toBe(0, $pint->getOutput())
        ->and($rector->getExitCode())->toBe(0, $rector->getOutput())
        ->and($analysis->identifiers)->toBe([])
        ->and($analysis->exitCode)->toBe(0);
});

it('gives a generated file for every step of every lock', function (): void {
    $files = new TypeTableMigrations(new FakeSchemaLocks, TypeTableLockJson::encode(...))->generate(MigrationFixtures::compile([
        'note.yaml' => MigrationFixtures::note(),
        'other.yaml' => MigrationFixtures::note(typeId: '01a0df3e-8cef-7e9f-8daf-9faa60f1fbd1', handle: 'other'),
    ]), SchemaFixtures::target());

    expect(array_map(static fn (GeneratedFile $file): string => $file->path, $files))->toBe([
        'database/migrations/cms/app__note.lock',
        'database/migrations/cms/app__note_0001_create.php',
        'database/migrations/cms/app__other.lock',
        'database/migrations/cms/app__other_0001_create.php',
    ]);
});
