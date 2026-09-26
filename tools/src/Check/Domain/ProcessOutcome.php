<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\Check\Domain;

/**
 * How a command ended. A command that could not start, was killed or timed out has no exit code
 * and did not succeed.
 */
final readonly class ProcessOutcome
{
    public function __construct(
        public ?int $exitCode,
        public string $output,
        public float $seconds,
        public bool $timedOut = false,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }
}
