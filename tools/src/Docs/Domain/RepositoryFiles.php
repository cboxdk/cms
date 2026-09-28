<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Domain;

/**
 * The files of the tree the check runs on, by repo-relative path, for what the markers name.
 */
interface RepositoryFiles
{
    /**
     * The bytes of the file, or null when there is no such regular file.
     */
    public function contents(string $path): ?string;

    /**
     * The PHP file read from its tokens, or null when there is no such regular file.
     */
    public function php(string $path): ?PhpFile;

    /**
     * Whether a regular file or a directory is at the path; the empty path is the root.
     */
    public function exists(string $path): bool;
}
