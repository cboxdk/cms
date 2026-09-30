<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Telemetry;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * A PSR-3 logger that records each entry with its level, message and context, and that throws for
 * every entry while it refuses, as a log channel whose disk or socket fails.
 */
final class RefusingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<array-key, mixed>}> */
    public array $records = [];

    public bool $refuses = false;

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if ($this->refuses) {
            throw new RuntimeException('The log channel refuses the entry.');
        }

        $this->records[] = [is_string($level) ? $level : 'unknown', (string) $message, $context];
    }
}
