<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Descriptor;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecords;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeCatalog;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintDocumentReader;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintSchemaFile;
use Cbox\Cms\Generators\Schema\Boundary\YamlBlueprintSource;
use Cbox\Cms\Generators\Schema\Domain\BlueprintRules;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\FieldTypeRegistry;
use Cbox\Cms\Generators\Schema\Domain\FieldTypes\CoreFieldTypes;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use FilesystemIterator;
use Illuminate\Support\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The comprehensive example type of the type chain (PRD 11.12, MILESTONES M1 point 1): the type
 * product of the module shop in Fixtures/Comprehensive/shop and the app's extension of it in
 * Fixtures/Comprehensive/app, read by the YAML source as cms:generate reads them, and the committed
 * golden file of its descriptor, Fixtures/Comprehensive/descriptor.json.
 */
final class ComprehensiveExample
{
    public const string DIRECTORY = __DIR__.'/Fixtures/Comprehensive';

    public const string GOLDEN = self::DIRECTORY.'/descriptor.json';

    /** The committed golden PHP of the example, below DIRECTORY: what the PHP generators write. */
    public const string PHP_DIRECTORY = 'Generated';

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
     * Where the generators write the example: the golden PHP in PHP_DIRECTORY, and TypeScript in a
     * directory that is not committed, because the PHP golden files are compared here.
     */
    public static function target(): GenerationTarget
    {
        return new GenerationTarget(self::DIRECTORY, self::roots(), self::PHP_DIRECTORY, self::PHP_NAMESPACE, 'typescript/generated');
    }

    /**
     * What the PHP generators write for the example: path below DIRECTORY to contents, sorted by path.
     *
     * @return array<string, string>
     */
    public static function php(): array
    {
        $files = [];

        foreach (new GeneratorRunner([new PhpTypeHandleEnum, new PhpRecords, new PhpTypeCatalog(ServiceProvider::class)])->run(self::compile(), self::target())->files as $file) {
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
        $files = [];
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::DIRECTORY.'/'.self::PHP_DIRECTORY, FilesystemIterator::SKIP_DOTS));

        foreach ($entries as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                $files[substr($entry->getPathname(), strlen(self::DIRECTORY) + 1)] = (string) file_get_contents($entry->getPathname());
            }
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    public static function compile(): CompiledSchema
    {
        return DescriptorCompiler::compile(SchemaResolver::resolve(self::source()->read(self::roots())));
    }
}
