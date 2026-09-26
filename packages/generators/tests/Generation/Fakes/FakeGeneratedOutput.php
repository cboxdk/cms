<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Generation\Fakes;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationResult;
use Cbox\Cms\Generators\Generation\Domain\Dto\WriteReport;
use Cbox\Cms\Generators\Generation\Domain\GeneratedOutput;
use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Override;

/**
 * Generated files in memory, by absolute path.
 *
 * A write stores the files whose contents differ, leaves identical ones alone, and removes the
 * files below the owned directories that the result does not contain, and it reports each list
 * as the filesystem output does. put() places a file, as an earlier run or a person would have;
 * block() makes a path unwritable, so a write that reaches it stops with
 * generate_output_unwritable after the files before it. GeneratedOutputBehaviour holds it to
 * FilesystemGeneratedOutput.
 */
final class FakeGeneratedOutput implements GeneratedOutput
{
    /** @var array<string, string> contents by absolute path */
    private array $files = [];

    /** @var array<string, true> */
    private array $blocked = [];

    #[Override]
    public function write(string $root, GenerationResult $result): WriteReport
    {
        $written = [];
        $unchanged = [];

        foreach ($result->files as $file) {
            $path = $root.'/'.$file->path;

            if (($this->files[$path] ?? null) === $file->contents) {
                $unchanged[] = $file->path;

                continue;
            }

            if (isset($this->blocked[$path])) {
                throw GenerationFailed::because(GenerateErrorCode::OutputUnwritable, sprintf('The generated file %s could not be written: the path is blocked.', $path));
            }

            $this->files[$path] = $file->contents;
            $written[] = $file->path;
        }

        return new WriteReport($written, $unchanged, $this->prune($root, $result));
    }

    public function put(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    public function block(string $path): void
    {
        $this->blocked[$path] = true;
    }

    public function contents(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    /**
     * The files below a directory, relative to it and sorted.
     *
     * @return list<string>
     */
    public function files(string $root): array
    {
        $files = [];

        foreach (array_keys($this->files) as $path) {
            if (str_starts_with($path, $root.'/')) {
                $files[] = substr($path, strlen($root) + 1);
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function prune(string $root, GenerationResult $result): array
    {
        $keep = array_flip($result->paths());
        $removed = [];

        foreach ($result->directories as $directory) {
            foreach ($this->files($root.'/'.$directory) as $below) {
                $relative = $directory.'/'.$below;

                if (! isset($keep[$relative])) {
                    unset($this->files[$root.'/'.$relative]);
                    $removed[] = $relative;
                }
            }
        }

        sort($removed, SORT_STRING);

        return $removed;
    }
}
