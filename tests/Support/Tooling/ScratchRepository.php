<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Tooling;

use Symfony\Component\Process\Process;

/**
 * A git repository in a scratch directory, for the tests of what reads git: files are written
 * and committed with a fixed author, no signing and no hooks.
 */
final readonly class ScratchRepository
{
    private function __construct(public string $root) {}

    public static function make(string $prefix = 'cbox-cms-repository-test-'): self
    {
        $repository = new self(ScratchDirectory::make($prefix));
        $repository->git('init', '--quiet', '--initial-branch=main');

        return $repository;
    }

    public function write(string $path, string $contents): self
    {
        ScratchDirectory::write($this->root.'/'.$path, $contents);

        return $this;
    }

    public function delete(string $path): self
    {
        $this->git('rm', '--quiet', '--', $path);

        return $this;
    }

    /**
     * Commits everything in the working tree and returns the commit.
     */
    public function commit(string $message): string
    {
        $this->git('add', '--all');
        $this->git('commit', '--quiet', '--allow-empty', '--message='.$message);

        return $this->git('rev-parse', 'HEAD');
    }

    public function git(string ...$arguments): string
    {
        $process = new Process(
            ['git', '-c', 'user.name=Scratch', '-c', 'user.email=scratch@example.test', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', ...array_values($arguments)],
            $this->root,
        );
        $process->mustRun();

        return trim($process->getOutput());
    }
}
