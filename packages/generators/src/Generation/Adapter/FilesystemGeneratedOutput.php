<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Generation\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;
use FilesystemIterator;
use Override;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Writes the generated code to the filesystem.
 *
 * A file whose contents are already right is not touched, so a second run changes nothing, not
 * even a modification time. A changed file is written to a temporary file in the same directory
 * and renamed into place. Files in the owned directories that the result does not contain are
 * removed, so the output is a function of the schema alone.
 *
 * The root is local: a root that names a stream wrapper, such as ftp://, is refused before any file
 * function sees it (GUARDRAILS 3).
 */
#[Internal]
final readonly class FilesystemGeneratedOutput implements GeneratedOutput
{
    #[Override]
    public function write(string $root, GenerationResult $result): WriteReport
    {
        if (LocalPath::namesStreamWrapper($root)) {
            throw GenerationFailed::because(GenerateErrorCode::OutputUnwritable, sprintf(
                'The root %s names a stream wrapper, and the generated code is written only to a local directory. Set cbox-cms.generators.root to an absolute local path.',
                $root,
            ));
        }

        $written = [];
        $unchanged = [];

        foreach ($result->files as $file) {
            $path = $root.'/'.$file->path;

            if (LocalFile::contents($path) === $file->contents) {
                $unchanged[] = $file->path;

                continue;
            }

            $this->replace($path, $file->contents);
            $written[] = $file->path;
        }

        return new WriteReport($written, $unchanged, $this->prune($root, $result));
    }

    /**
     * Removes the files in the owned directories that the result does not contain.
     *
     * @return list<string> the removed paths, relative to the root and sorted
     */
    private function prune(string $root, GenerationResult $result): array
    {
        $keep = array_flip($result->paths());
        $removed = [];

        foreach ($result->directories as $directory) {
            if (! is_dir($root.'/'.$directory)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $entry) {
                if (! $entry instanceof SplFileInfo || $entry->isDir()) {
                    continue;
                }

                $relative = $directory.'/'.substr($entry->getPathname(), strlen($root.'/'.$directory.'/'));

                if (isset($keep[$relative])) {
                    continue;
                }

                $failure = $this->attempt(static fn (): bool => unlink($entry->getPathname()), 'the file could not be removed');

                if ($failure !== null) {
                    throw GenerationFailed::because(GenerateErrorCode::OutputUnwritable, sprintf('The stale generated file %s could not be removed: %s', $entry->getPathname(), $failure));
                }

                $removed[] = $relative;
            }
        }

        sort($removed, SORT_STRING);

        return $removed;
    }

    private function replace(string $path, string $contents): void
    {
        $directory = dirname($path);

        $failure = is_dir($directory)
            ? null
            : $this->attempt(static fn (): bool => mkdir($directory, 0o775, true) || is_dir($directory), 'the directory could not be created');

        $temporary = sprintf('%s.%s.tmp', $path, bin2hex(random_bytes(8)));

        $failure ??= $this->attempt(static fn (): bool => file_put_contents($temporary, $contents) === strlen($contents), 'the file could not be written')
            ?? $this->attempt(static fn (): bool => rename($temporary, $path), 'the file could not be moved into place');

        if ($failure !== null) {
            $this->attempt(static fn (): bool => ! is_file($temporary) || unlink($temporary), 'the temporary file could not be removed');

            throw GenerationFailed::because(GenerateErrorCode::OutputUnwritable, sprintf('The generated file %s could not be written: %s', $path, $failure));
        }
    }

    /**
     * Runs a filesystem call and turns its warning into the reason it failed.
     *
     * @param  callable(): bool  $operation
     * @return string|null null when it succeeded, otherwise why it failed
     */
    private function attempt(callable $operation, string $fallback): ?string
    {
        $warning = null;

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $succeeded = $operation();
        } finally {
            restore_error_handler();
        }

        return $succeeded ? null : ($warning ?? $fallback);
    }
}
