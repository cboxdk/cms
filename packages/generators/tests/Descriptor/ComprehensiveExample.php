<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Descriptor;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecordDtos;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecords;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeCatalog;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeQueries;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeValidators;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeTableMigrations;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Migrations\Domain\TableChanges;
use Cbox\Cms\Generators\Migrations\Domain\TypeTableDdl;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintDocumentReader;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\BlueprintRules;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\Tests\Migrations\Fakes\FakeSchemaLocks;
use FilesystemIterator;
use Illuminate\Support\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The comprehensive example type of the type chain (PRD 11.12, MILESTONES M1 point 1): the type
 * product of the module shop in Fixtures/Comprehensive/shop and the app's extension of it in
 * Fixtures/Comprehensive/app, read by the YAML source as cms:generate reads them, and the committed
 * golden files of its descriptor, Fixtures/Comprehensive/descriptor.json, of its type table's DDL,
 * Fixtures/Comprehensive/ddl.sql, and of its migration and schema lock, in
 * Fixtures/Comprehensive/migrations/cms.
 */
final class ComprehensiveExample
{
    public const string DIRECTORY = __DIR__.'/Fixtures/Comprehensive';

    public const string GOLDEN = self::DIRECTORY.'/descriptor.json';

    /** The committed golden PHP of the example, below DIRECTORY: what the PHP generators write, the record DTOs and codecs of PhpRecordDtos and the query builder of PhpTypeQueries included. */
    public const string PHP_DIRECTORY = 'Generated';

    /** The committed golden migrations and schema lock of the example's type table, below DIRECTORY. */
    public const string MIGRATIONS_DIRECTORY = 'migrations/cms';

    /** The committed golden DDL of the example's type table: the statements of its first migration. */
    public const string GOLDEN_DDL = self::DIRECTORY.'/ddl.sql';

    /** The namespace of the golden PHP, which Composer's autoload-dev loads from PHP_DIRECTORY. */
    public const string PHP_NAMESPACE = 'Cbox\\Cms\\Generators\\Tests\\Descriptor\\Fixtures\\Comprehensive\\Generated';

    /**
     * The schema roots of the example: the module shop, which owns the type, and the app.
     *
     * @return list<SchemaRoot>
     */
    public static function roots(): array
    {
        return [
            new SchemaRoot(new Owner('app'), self::DIRECTORY, 'app'),
            new SchemaRoot(new Owner('shop'), self::DIRECTORY, 'shop'),
        ];
    }

    /**
     * The YAML source with the core's field types, as cms:generate reads the blueprints.
     */
    public static function source(): YamlBlueprintSource
    {
        return new YamlBlueprintSource(new BlueprintSchemaFile, new BlueprintDocumentReader(new FieldTypeRegistry(new CoreFieldTypes)), new BlueprintRules);
    }

    /**
     * Where the generators write the example: the golden PHP in PHP_DIRECTORY, the TypeScript in
     * typescript/generated, whose golden files the TypeScript tests compare, and the migrations in
     * MIGRATIONS_DIRECTORY.
     */
    public static function target(): GenerationTarget
    {
        return new GenerationTarget(self::DIRECTORY, self::roots(), self::PHP_DIRECTORY, self::PHP_NAMESPACE, 'typescript/generated', self::MIGRATIONS_DIRECTORY);
    }

    /**
     * What TypeTableMigrations writes for the example as a new type, without a schema lock: path
     * below DIRECTORY to contents, sorted by path.
     *
     * @return array<string, string>
     */
    public static function migrations(): array
    {
        $files = [];

        foreach (new TypeTableMigrations(new FakeSchemaLocks)->generate(self::compile(), self::target()) as $file) {
            $files[$file->path] = $file->contents;
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * The DDL of the example's type table as a new type: the statements of its first migration,
     * each ending with a semicolon, with a blank line between them, and a comment that says what
     * the file is.
     */
    public static function ddl(): string
    {
        $statements = TypeTableDdl::create(TableChanges::next(self::compile(), [])[0]);

        return "-- The DDL of the type table of the comprehensive example, shop:product extended by app\n"
            ."-- (PRD 4.1, 11.6): the statements of its first migration, before its grants and row level\n"
            ."-- security. TypeTableMigrationsTest compares it with what the generator writes.\n\n"
            .implode(";\n\n", $statements).";\n";
    }

    /**
     * The committed golden migrations and schema lock: path below DIRECTORY to contents, sorted by
     * path.
     *
     * @return array<string, string>
     */
    public static function goldenMigrations(): array
    {
        return self::below(self::MIGRATIONS_DIRECTORY);
    }

    /**
     * What the PHP generators write for the example: path below DIRECTORY to contents, sorted by path.
     *
     * @return array<string, string>
     */
    public static function php(): array
    {
        $files = [];

        foreach (new GeneratorRunner([new PhpRecordDtos, new PhpTypeHandleEnum, new PhpRecords, new PhpTypeCatalog(ServiceProvider::class), new PhpTypeQueries, new PhpTypeValidators])->run(self::compile(), self::target())->files as $file) {
            $files[$file->path] = $file->contents;
        }

        return $files;
    }

    /**
     * The committed golden PHP files: path below DIRECTORY to contents, sorted by path.
     *
     * @return array<string, string>
     */
    public static function goldenPhp(): array
    {
        return self::below(self::PHP_DIRECTORY);
    }

    public static function compile(): CompiledSchema
    {
        return DescriptorCompiler::compile(SchemaResolver::resolve(self::source()->read(self::roots())));
    }

    /**
     * The files below a directory of the example: path below DIRECTORY to contents, sorted by path.
     *
     * @return array<string, string>
     */
    private static function below(string $directory): array
    {
        $files = [];
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::DIRECTORY.'/'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                $files[substr($entry->getPathname(), strlen(self::DIRECTORY) + 1)] = (string) file_get_contents($entry->getPathname());
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }
}
