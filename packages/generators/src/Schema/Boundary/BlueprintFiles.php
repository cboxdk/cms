<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Finds the blueprint files below schema roots on the filesystem (PRD 11.12): every `*.yaml` file
 * below a root, at any depth. cms:generate reads them and cms:schema:editor writes their editor
 * line, so both see the same files and refuse the same roots.
 */
#[Internal]
final readonly class BlueprintFiles
{
    public const string EXTENSION = 'yaml';

    /**
     * The blueprint files below a root, each with its path and the name problems give it: the
     * root's directory and the file's path below it.
     *
     * @param  list<GenerationProblem>  $problems  gets generate_schema_missing when the root or a directory below it cannot be read
     * @return list<array{root: SchemaRoot, path: string, file: string}>
     */
    public static function below(SchemaRoot $root, array &$problems): array
    {
        $directory = $root->path();

        if (! is_dir($directory) || ! is_readable($directory)) {
            $problems[] = new GenerationProblem(GenerateErrorCode::SchemaMissing, sprintf(
                'The schema root %s of %s does not exist or cannot be read. Create the directory, or remove the root.',
                $directory,
                $root->owner->value,
            ));

            return [];
        }

        $files = [];

        try {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS));

            foreach ($entries as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === self::EXTENSION) {
                    $relative = substr($entry->getPathname(), strlen($directory) + 1);
                    $files[] = ['root' => $root, 'path' => $entry->getPathname(), 'file' => $root->file($relative)];
                }
            }
        } catch (UnexpectedValueException $unreadable) {
            $problems[] = new GenerationProblem(GenerateErrorCode::SchemaMissing, sprintf(
                'A directory below the schema root %s of %s cannot be read: %s',
                $directory,
                $root->owner->value,
                $unreadable->getMessage(),
            ));
        }

        return $files;
    }

    /**
     * A generate_invalid_config problem for each pair of roots where one lies in the other, so no
     * file is read or written twice.
     *
     * @param  list<SchemaRoot>  $roots
     * @return list<GenerationProblem>
     */
    public static function overlapping(array $roots): array
    {
        $problems = [];
        $paths = array_map(static fn (SchemaRoot $root): string|false => realpath($root->path()), $roots);

        foreach ($roots as $i => $root) {
            foreach ($roots as $j => $other) {
                $path = $paths[$i];
                $otherPath = $paths[$j];

                if ($i === $j || ! is_string($path) || ! is_string($otherPath)) {
                    continue;
                }

                if (str_starts_with($otherPath, $path.'/') || ($path === $otherPath && $i < $j)) {
                    $problems[] = new GenerationProblem(GenerateErrorCode::InvalidConfig, sprintf(
                        'The schema root %s of %s lies in the schema root %s of %s, so its files would be read twice. Give each directory once.',
                        $other->path(),
                        $other->owner->value,
                        $root->path(),
                        $root->owner->value,
                    ));
                }
            }
        }

        return $problems;
    }
}
