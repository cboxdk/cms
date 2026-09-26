<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Selftest\Domain;

use InvalidArgumentException;

/**
 * One known violation that `composer check:selftest` plants in its worktree, and where it must be
 * caught: the gate, the step of that gate, and text the step's output must contain besides the
 * file's path, such as the rule's identifier.
 */
final readonly class Plant
{
    /**
     * @param  string  $path  relative to the worktree
     * @param  string  $contents  the new file, or the text appended to an existing file
     * @param  list<string>  $markers
     */
    public function __construct(
        public int $gate,
        public string $step,
        public string $violation,
        public string $path,
        public string $contents,
        public bool $append,
        public array $markers,
    ) {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new InvalidArgumentException("A plant's path is relative to the worktree and stays inside it, got '{$path}'.");
        }

        if ($contents === '') {
            throw new InvalidArgumentException("The plant for {$path} has no contents.");
        }
    }

    /**
     * Writes the violation into the worktree. A new file must not exist yet, and a file to append
     * to must.
     */
    public function plantIn(string $worktree): void
    {
        $file = $worktree.'/'.$this->path;

        if ($this->append !== is_file($file)) {
            throw new SelftestFailed($this->append
                ? "Cannot plant {$this->violation}: {$this->path} does not exist in the worktree."
                : "Cannot plant {$this->violation}: {$this->path} already exists in the worktree.");
        }

        if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0o777, true)) {
            throw new SelftestFailed("Cannot create the directory for {$this->path}.");
        }

        if (file_put_contents($file, $this->contents, $this->append ? FILE_APPEND : 0) === false) {
            throw new SelftestFailed("Cannot write {$this->path}.");
        }
    }
}
