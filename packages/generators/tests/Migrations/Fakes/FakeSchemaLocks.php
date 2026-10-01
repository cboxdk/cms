<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Migrations\Fakes;

use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Migrations\Boundary\TypeTableLockJson;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;
use Cbox\Cms\Generators\Migrations\Domain\SchemaLocks;
use Override;

/**
 * SchemaLocks in memory, for the generators' tests: files by their absolute path, of which read()
 * takes the `*.lock` files directly in the directory and decodes them as LockFiles does. The shared
 * SchemaLocksBehaviour holds it to LockFiles.
 */
final class FakeSchemaLocks implements SchemaLocks
{
    /** @var array<string, string> path to contents */
    private array $files = [];

    /**
     * The fake with the locks in the directory, as cms:generate writes them.
     *
     * @param  list<TypeTableLock>  $locks
     */
    public static function with(string $root, string $directory, array $locks): self
    {
        $fake = new self;

        foreach ($locks as $lock) {
            $fake->put($root.'/'.$directory.'/'.$lock->file(), TypeTableLockJson::encode($lock));
        }

        return $fake;
    }

    public function put(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    #[Override]
    public function read(string $root, string $directory): array
    {
        $files = [];

        foreach ($this->files as $path => $contents) {
            if (dirname($path) === $root.'/'.$directory && pathinfo($path, PATHINFO_EXTENSION) === TypeTableLock::EXTENSION) {
                $files[basename($path)] = $contents;
            }
        }

        ksort($files, SORT_STRING);
        $locks = [];
        $problems = [];

        foreach ($files as $name => $contents) {
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
