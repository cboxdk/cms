<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Editor\Fakes;

use Cbox\Cms\Generators\Editor\Domain\Dto\SchemaFile;
use Cbox\Cms\Generators\Editor\Domain\SchemaFiles;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Domain\Dto\SchemaRoot;
use Override;

/**
 * Blueprint files in memory, by absolute path, with canonical paths only: a file's directory is
 * the directory of its path.
 *
 * put() places a file and its directories, directory() places an empty directory. block() makes
 * a file unwritable and hide() unreadable, with the codes and message forms of the filesystem.
 * $writes lists each path written, in order. SchemaFilesBehaviour holds it to
 * FilesystemSchemaFiles.
 */
final class FakeSchemaFiles implements SchemaFiles
{
    /** @var list<string> */
    public array $writes = [];

    /** @var array<string, string> contents by absolute path */
    private array $files = [];

    /** @var array<string, true> */
    private array $directories = [];

    /** @var array<string, true> */
    private array $blocked = [];

    /** @var array<string, true> */
    private array $hidden = [];

    #[Override]
    public function find(array $roots): array
    {
        $problems = $this->overlapping($roots);

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        $found = [];

        foreach ($roots as $root) {
            $directory = $root->path();

            if (! isset($this->directories[$directory])) {
                $problems[] = new GenerationProblem(GenerateErrorCode::SchemaMissing, sprintf(
                    'The schema root %s of %s does not exist or cannot be read. Create the directory, or remove the root.',
                    $directory,
                    $root->owner->value,
                ));

                continue;
            }

            foreach (array_keys($this->files) as $path) {
                if (str_starts_with($path, $directory.'/') && str_ends_with($path, '.yaml')) {
                    $found[] = new SchemaFile($path, $root->file(substr($path, strlen($directory) + 1)), dirname($path));
                }
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        usort($found, static fn (SchemaFile $a, SchemaFile $b): int => strcmp($a->file, $b->file));

        return $found;
    }

    #[Override]
    public function read(SchemaFile $file): string
    {
        if (isset($this->hidden[$file->path]) || ! isset($this->files[$file->path])) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf('%s cannot be read. Check its permissions.', $file->file));
        }

        return $this->files[$file->path];
    }

    #[Override]
    public function write(SchemaFile $file, string $contents): void
    {
        if (! isset($this->files[$file->path]) || isset($this->blocked[$file->path])) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaUnwritable, sprintf(
                '%s cannot be written: %s. Its editor line was not changed; make it writable and run cms:schema:editor again.',
                $file->file,
                isset($this->files[$file->path]) ? 'the file is read-only' : 'the file no longer exists',
            ));
        }

        $this->files[$file->path] = $contents;
        $this->writes[] = $file->path;
    }

    public function put(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
        $this->directory(dirname($path));
    }

    public function directory(string $path): void
    {
        for ($directory = $path; $directory !== '/' && $directory !== '.' && ! isset($this->directories[$directory]); $directory = dirname($directory)) {
            $this->directories[$directory] = true;
        }
    }

    public function block(string $path): void
    {
        $this->blocked[$path] = true;
    }

    public function hide(string $path): void
    {
        $this->hidden[$path] = true;
    }

    public function contents(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    /**
     * @param  list<SchemaRoot>  $roots
     * @return list<GenerationProblem>
     */
    private function overlapping(array $roots): array
    {
        $problems = [];

        foreach ($roots as $i => $root) {
            foreach ($roots as $j => $other) {
                if ($i === $j || ! isset($this->directories[$root->path()], $this->directories[$other->path()])) {
                    continue;
                }

                if (str_starts_with($other->path(), $root->path().'/') || ($root->path() === $other->path() && $i < $j)) {
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
