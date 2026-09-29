<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Migrations\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Storage\LocalPath;
use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationProblem;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;
use Cbox\Cms\Generators\Migrations\Domain\SchemaLocks;
use Cbox\Cms\Generators\Schema\Boundary\LocalFile;
use FilesystemIterator;
use Override;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Reads the schema locks of the type tables from the migrations directory on the filesystem
 * (PRD 11.6): every `*.lock` file directly in it, decoded by TypeTableLockJson. Every lock that
 * cannot be read is reported before it fails. The root is local: a path that names a stream
 * wrapper is refused before any file function sees it (GUARDRAILS 3).
 */
#[Internal]
final readonly class LockFiles implements SchemaLocks
{
    #[Override]
    public function read(string $root, string $directory): array
    {
        $path = $root.'/'.$directory;

        if (LocalPath::namesStreamWrapper($path)) {
            throw GenerationFailed::because(GenerateErrorCode::InvalidConfig, sprintf(
                'The migrations directory %s names a stream wrapper, and the schema locks are read only from a local directory. Set cbox-cms.generators.root to an absolute local path.',
                $path,
            ));
        }

        if (! is_dir($path)) {
            return [];
        }

        $files = [];

        try {
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS) as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === TypeTableLock::EXTENSION) {
                    $files[$entry->getFilename()] = $entry->getPathname();
                }
            }
        } catch (UnexpectedValueException $unreadable) {
            throw GenerationFailed::because(GenerateErrorCode::SchemaMissing, sprintf(
                'The migrations directory %s cannot be listed: %s',
                $path,
                $unreadable->getMessage(),
            ), $unreadable);
        }

        ksort($files, SORT_STRING);
        $locks = [];
        $problems = [];

        foreach ($files as $name => $file) {
            $contents = LocalFile::contents($file);

            if ($contents === null) {
                $problems[] = new GenerationProblem(GenerateErrorCode::LockInvalid, sprintf('The schema lock %s cannot be read.', $directory.'/'.$name));

                continue;
            }

            try {
                $locks[] = TypeTableLockJson::decode($directory.'/'.$name, $contents);
            } catch (GenerationFailed $failed) {
                array_push($problems, ...$failed->problems);
            }
        }

        if ($problems !== []) {
            throw GenerationFailed::with($problems);
        }

        return $locks;
    }
}
