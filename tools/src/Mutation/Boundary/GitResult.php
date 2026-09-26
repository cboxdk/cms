<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Mutation\Boundary;

/**
 * How one git command ended: its exit code, its output trimmed and as it came, and its error
 * output on one line, or the exit code when it printed none.
 */
final readonly class GitResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $raw,
        public string $message,
    ) {}
}
