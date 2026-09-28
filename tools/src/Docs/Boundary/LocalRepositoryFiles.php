<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Docs\Boundary;

use Cbox\Cms\Tooling\Docs\Domain\PhpFile;
use Cbox\Cms\Tooling\Docs\Domain\RepositoryFiles;

/**
 * The files below a root directory, by repo-relative path. A path that leaves the root, or that is
 * not a regular file, has no contents, and a path that leaves the root does not exist.
 */
final readonly class LocalRepositoryFiles implements RepositoryFiles
{
    public function __construct(private string $root) {}

    public function contents(string $path): ?string
    {
        if ($path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            return null;
        }

        $file = $this->root.'/'.$path;

        if (! is_file($file)) {
            return null;
        }

        $contents = file_get_contents($file);

        return $contents === false ? null : $contents;
    }

    public function php(string $path): ?PhpFile
    {
        $contents = $this->contents($path);

        return $contents === null ? null : PhpTokens::read($path, $contents);
    }

    public function exists(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        if (str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            return false;
        }

        $file = $this->root.'/'.rtrim($path, '/');

        return is_file($file) || is_dir($file);
    }
}
