<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * A comment token and the namespace it appears in. A comment before the namespace
 * statement, or in a file without one, is in the global namespace ('').
 */
final readonly class Comment
{
    public function __construct(
        public string $namespace,
        public string $text,
        public string $path,
        public int $line,
    ) {}

    public function location(): string
    {
        return $this->path.':'.$this->line;
    }
}
