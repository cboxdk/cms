<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Editor\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Generators\Editor\Domain\Dto\SchemaFile;
use Cbox\Cms\Generators\Editor\Domain\SchemaFiles;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Schema\Boundary\BlueprintFiles;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;
use Override;

/**
 * The blueprint files on the filesystem, found as the reader finds them (BlueprintFiles).
 *
 * A file's directory is the real path of the directory it was found in. A write refuses a file
 * that is not writable, writes a temporary file beside the real file with the same permissions and
 * renames it into place, so a file is never left half written and a symlink keeps pointing at it.
 */
#[Internal]
final readonly class FilesystemSchemaFiles implements SchemaFiles
{
    #[Override]
    public function find(array $roots): array
    {
        $problems = BlueprintFiles::overlapping($roots);

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        $files = [];

        foreach ($roots as $root) {
            foreach (BlueprintFiles::below($root, $problems) as $found) {
                $directory = realpath(dirname($found['path']));

                if ($directory === false) {
                    $problems[] = new GenerationProblem(GenerateErrorCode::SchemaMissing, sprintf('The directory of %s cannot be resolved. Check its permissions.', $found['file']));

                    continue;
                }

                $files[] = new SchemaFile($found['path'], $found['file'], $directory);
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        usort($files, static fn (SchemaFile $a, SchemaFile $b): int => strcmp($a->file, $b->file));

        return $files;
    }

    #[Override]
    public function read(SchemaFile $file): string
    {
        return LocalFile::contents($file->path)
            ?? throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf('%s cannot be read. Check its permissions.', $file->file));
    }

    #[Override]
    public function write(SchemaFile $file, string $contents): void
    {
        $target = realpath($file->path);

        if ($target === false || ! is_file($target)) {
            throw $this->unwritable($file, 'the file no longer exists');
        }

        if (! is_writable($target)) {
            throw $this->unwritable($file, 'the file is read-only');
        }

        $permissions = fileperms($target);
        $temporary = sprintf('%s/.%s.%s.tmp', dirname($target), basename($target), bin2hex(random_bytes(8)));

        $failure = $this->attempt(static fn (): bool => file_put_contents($temporary, $contents) === strlen($contents), 'the file could not be written')
            ?? ($permissions === false ? null : $this->attempt(static fn (): bool => chmod($temporary, $permissions & 0o7777), 'its permissions could not be kept'))
            ?? $this->attempt(static fn (): bool => rename($temporary, $target), 'the file could not be moved into place');

        if ($failure !== null) {
            $this->attempt(static fn (): bool => ! is_file($temporary) || unlink($temporary), 'the temporary file could not be removed');

            throw $this->unwritable($file, $failure);
        }
    }

    private function unwritable(SchemaFile $file, string $reason): GenerationFailed
    {
        return GenerationFailed::because(GenerateErrorCode::SchemaUnwritable, sprintf(
            '%s cannot be written: %s. Its editor line was not changed; make it writable and run cms:schema:editor again.',
            $file->file,
            $reason,
        ));
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
