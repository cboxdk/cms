<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Schema\Domain\FieldDefinition;
use Cbox\Cms\Generators\Schema\Domain\FixtureSchema;
use Cbox\Cms\Generators\Schema\Domain\Handle;
use Cbox\Cms\Generators\Schema\Domain\TypeDefinition;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Builds schemas, targets and scratch directories for the generator tests.
 */
final class SchemaFixtures
{
    /** @var list<string> */
    private static array $scratch = [];

    /**
     * A schema from type handle to field handle to field type, in the given order.
     *
     * @param  array<string, array<string, string>>  $types
     */
    public static function schema(array $types): FixtureSchema
    {
        $definitions = [];

        foreach ($types as $type => $fields) {
            $definitions[] = new TypeDefinition(
                new Handle($type),
                ucfirst(str_replace('_', ' ', $type)),
                array_map(
                    static fn (string $field, string $fieldType): FieldDefinition => new FieldDefinition(new Handle($field), new Handle($fieldType)),
                    array_keys($fields),
                    array_values($fields),
                ),
            );
        }

        return new FixtureSchema($definitions);
    }

    public static function target(string $root = '/srv/app'): GenerationTarget
    {
        return new GenerationTarget($root, 'schema/fixture.yaml', 'app/Cms/Generated', 'App\Cms\Generated', 'resources/js/cms/generated');
    }

    public static function scratch(): string
    {
        $directory = sys_get_temp_dir().'/cms-generators-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o775, true);
        self::$scratch[] = $directory;

        return (string) realpath($directory);
    }

    public static function write(string $path, string $contents): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * Every file below the directory, relative to it and sorted.
     *
     * @return list<string>
     */
    public static function files(string $directory): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                $files[] = substr($entry->getPathname(), strlen($directory) + 1);
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    public static function cleanUp(): void
    {
        foreach (self::$scratch as $directory) {
            self::remove($directory);
        }

        self::$scratch = [];
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (new FilesystemIterator($path) as $entry) {
            if ($entry instanceof SplFileInfo) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    chmod($entry->getPathname(), 0o775);
                }

                self::remove($entry->getPathname());
            }
        }

        rmdir($path);
    }
}
