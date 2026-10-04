<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Scaffold\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Generators\Generation\Domain\Dto\GeneratedFile;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Scaffold\Boundary\ComposerManifest;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\AddonPackage;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldReport;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldResult;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldOutput;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;
use Override;

/**
 * The scaffold's files on the local filesystem: composer.json read as the package, files read
 * where they are, and each file written through a temporary file and a rename, so a reader never
 * meets a half-written file. A file the result writes only where none exists is kept when the
 * addon has it.
 */
#[Internal]
final readonly class FilesystemScaffoldOutput implements ScaffoldOutput
{
    #[Override]
    public function package(string $root): AddonPackage
    {
        $json = $this->read($root, 'composer.json');

        if ($json === null) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
                'The addon\'s package %s has no readable composer.json, so its name and namespaces are unknown.',
                $root,
            ));
        }

        return ComposerManifest::read($json, $root.'/composer.json');
    }

    #[Override]
    public function read(string $root, string $path): ?string
    {
        return LocalFile::contents($root.'/'.$path);
    }

    #[Override]
    public function write(string $root, ScaffoldResult $result): ScaffoldReport
    {
        if (LocalPath::namesStreamWrapper($root)) {
            throw GenerationFailed::because(GenerateErrorCode::OutputUnwritable, sprintf(
                'The root %s names a stream wrapper, and a scaffold is written only to a local directory.',
                $root,
            ));
        }

        $written = [];
        $kept = [];

        foreach ($result->files as $file) {
            if (is_file($root.'/'.$file->path)) {
                $kept[] = $file->path;

                continue;
            }

            $this->replace($root.'/'.$file->path, $file);
            $written[] = $file->path;
        }

        foreach ($result->updates as $file) {
            if (LocalFile::contents($root.'/'.$file->path) === $file->contents) {
                $kept[] = $file->path;

                continue;
            }

            $this->replace($root.'/'.$file->path, $file);
            $written[] = $file->path;
        }

        sort($written, SORT_STRING);
        sort($kept, SORT_STRING);

        return new ScaffoldReport($written, $kept, $result->notes);
    }

    /**
     * @throws GenerationFailed with generate_output_unwritable
     */
    private function replace(string $path, GeneratedFile $file): void
    {
        $directory = dirname($path);
        $failure = is_dir($directory)
            ? null
            : $this->attempt(static fn (): bool => mkdir($directory, 0o775, true) || is_dir($directory), 'the directory could not be created');
        $temporary = sprintf('%s.%s.tmp', $path, bin2hex(random_bytes(8)));
        $failure ??= $this->attempt(static fn (): bool => file_put_contents($temporary, $file->contents) === strlen($file->contents), 'the file could not be written')
            ?? $this->attempt(static fn (): bool => rename($temporary, $path), 'the file could not be moved into place');

        if ($failure !== null) {
            $this->attempt(static fn (): bool => ! is_file($temporary) || unlink($temporary), 'the temporary file could not be removed');

            throw GenerationFailed::because(GenerateErrorCode::OutputUnwritable, sprintf('The scaffolded file %s could not be written: %s', $path, $failure));
        }
    }

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
