<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Tests\Console;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps what was logged.
 */
final class FakeLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<array-key, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [is_string($level) ? $level : 'unknown', (string) $message, $context];
    }
}
