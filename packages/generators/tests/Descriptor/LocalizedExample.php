<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Descriptor;

use Cbox\Cms\Generators\Descriptor\Domain\DescriptorCompiler;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\CompiledSchema;
use Cbox\Cms\Generators\Generation\Boundary\TypeScriptRuntime;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecordDtos;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpRecords;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeCatalog;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeEditors;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeQueries;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeValidators;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptContracts;
use Cbox\Cms\Generators\Generation\Domain\SchemaResolver;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Cbox\Cms\Generators\Schema\Domain\Owner;
use Cbox\Cms\Generators\TypeEditors\Boundary\RuleJson;
use Cbox\Cms\Generators\TypeEditors\Domain\TypeEditorCompiler;
use FilesystemIterator;
use Illuminate\Support\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The localized example type of the type chain (PRD 10.1, 10.3, 11.6): the type post of the module
 * blog in Fixtures/Localized/blog, with localization variants, and the app's extension of it in
 * Fixtures/Localized/app, read by the YAML source as cms:generate reads them, and the committed
 * golden files of its descriptor, Fixtures/Localized/descriptor.json, its editor descriptor,
 * Fixtures/Localized/editor.json, its PHP below Fixtures/Localized/Generated and its TypeScript
 * below Fixtures/Localized/typescript/generated.
 */
final class LocalizedExample
{
    public const string DIRECTORY = __DIR__.'/Fixtures/Localized';

    public const string GOLDEN = self::DIRECTORY.'/descriptor.json';

    public const string GOLDEN_EDITOR = self::DIRECTORY.'/editor.json';

    /** The committed golden PHP of the example, below DIRECTORY. */
    public const string PHP_DIRECTORY = 'Generated';

    /** The committed golden TypeScript of the example, below DIRECTORY. */
    public const string TYPESCRIPT_DIRECTORY = 'typescript/generated';

    /** The namespace of the golden PHP, which Composer's autoload-dev loads from PHP_DIRECTORY. */
    public const string PHP_NAMESPACE = 'Cbox\\Cms\\Generators\\Tests\\Descriptor\\Fixtures\\Localized\\Generated';

    /**
     * The schema roots of the example: the module blog, which owns the type, and the app.
     *
     * @return list<SchemaRoot>
     */
    public static function roots(): array
    {
        return [
            new SchemaRoot(new Owner('app'), self::DIRECTORY, 'app'),
            new SchemaRoot(new Owner('blog'), self::DIRECTORY, 'blog'),
        ];
    }

    /**
     * Where the generators write the example: the golden PHP in PHP_DIRECTORY and the TypeScript
     * in TYPESCRIPT_DIRECTORY.
     */
    public static function target(): GenerationTarget
    {
        return new GenerationTarget(self::DIRECTORY, self::roots(), self::PHP_DIRECTORY, self::PHP_NAMESPACE, self::TYPESCRIPT_DIRECTORY);
    }

    public static function compile(): CompiledSchema
    {
        return DescriptorCompiler::compile(SchemaResolver::resolve(ComprehensiveExample::source()->read(self::roots())));
    }

    /**
     * What the PHP generators write for the example: path below DIRECTORY to contents, sorted by path.
     *
     * @return array<string, string>
     */
    public static function php(): array
    {
        return self::generated(new GeneratorRunner([new PhpRecordDtos, new PhpTypeHandleEnum, new PhpRecords, new PhpTypeCatalog(ServiceProvider::class), new PhpTypeEditors(new TypeEditorCompiler(RuleJson::of(...))), new PhpTypeQueries, new PhpTypeValidators]));
    }

    /**
     * What the TypeScript generator writes for the example's records, without the kernel's
     * contracts: path below DIRECTORY to contents, sorted by path.
     *
     * @return array<string, string>
     */
    public static function typeScript(): array
    {
        return self::generated(new GeneratorRunner([new TypeScriptContracts(new TypeScriptRuntime()->source(...), static fn (): array => [])]));
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

    /**
     * The committed golden TypeScript files: path below DIRECTORY to contents, sorted by path.
     *
     * @return array<string, string>
     */
    public static function goldenTypeScript(): array
    {
        return self::below(self::TYPESCRIPT_DIRECTORY);
    }

    /**
     * @return array<string, string>
     */
    private static function generated(GeneratorRunner $runner): array
    {
        $files = [];

        foreach ($runner->run(self::compile(), self::target())->files as $file) {
            $files[$file->path] = $file->contents;
        }

        ksort($files, SORT_STRING);

        return $files;
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
