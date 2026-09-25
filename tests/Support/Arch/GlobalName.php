<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\Arch;

/**
 * A name that resolves from the global namespace: a fully qualified name such as \DB, or
 * a top-level import such as `use DB;`. The leading backslash is removed.
 */
final readonly class GlobalName
{
    public function __construct(
        public string $name,
        public string $path,
        public int $line,
    ) {}

    public function location(): string
    {
        return $this->path.':'.$this->line;
    }
}
