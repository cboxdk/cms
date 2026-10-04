<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Scaffold\Fakes;

use Cbox\Cms\Generators\Generation\Domain\GenerateErrorCode;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;
use Cbox\Cms\Generators\Scaffold\Boundary\ComposerManifest;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\AddonPackage;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldReport;
use Cbox\Cms\Generators\Scaffold\Domain\Dto\ScaffoldResult;
use Cbox\Cms\Generators\Scaffold\Domain\ScaffoldOutput;
use Override;

/**
 * A scaffold output in memory, for the scaffold actions' tests: files by absolute path, a
 * composer.json read as the package, and paths a test blocks, which cannot be written.
 * ScaffoldOutputBehaviour holds it to FilesystemScaffoldOutput.
 */
final class FakeScaffoldOutput implements ScaffoldOutput
{
    /** @var array<string, string> */
    private array $files = [];

    /** @var array<string, true> */
    private array $blocked = [];

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
        return $this->files[$root.'/'.$path] ?? null;
    }

    #[Override]
    public function write(string $root, ScaffoldResult $result): ScaffoldReport
    {
        $written = [];
        $kept = [];

        foreach ($result->files as $file) {
            if (array_key_exists($root.'/'.$file->path, $this->files)) {
                $kept[] = $file->path;

                continue;
            }

            $this->replace($root.'/'.$file->path, $file->contents);
            $written[] = $file->path;
        }

        foreach ($result->updates as $file) {
            if (($this->files[$root.'/'.$file->path] ?? null) === $file->contents) {
                $kept[] = $file->path;

                continue;
            }

            $this->replace($root.'/'.$file->path, $file->contents);
            $written[] = $file->path;
        }

        sort($written, SORT_STRING);
        sort($kept, SORT_STRING);

        return new ScaffoldReport($written, $kept, $result->notes);
    }

    /**
     * Places a file, as the addon's author or an earlier run would have.
     */
    public function put(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    /**
     * Makes the path impossible to write.
     */
    public function block(string $path): void
    {
        $this->blocked[$path] = true;
    }

    public function contents(string $path): ?string
    {
        return $this->files[$path] ?? null;
    }

    /**
     * Every file below the root, relative to it and sorted.
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
     * @throws GenerationFailed with generate_output_unwritable
     */
    private function replace(string $path, string $contents): void
    {
        if (isset($this->blocked[$path])) {
            throw GenerationFailed::because(GenerateErrorCode::OutputUnwritable, sprintf('The scaffolded file %s could not be written: the path is blocked', $path));
        }

        $this->files[$path] = $contents;
    }
}
