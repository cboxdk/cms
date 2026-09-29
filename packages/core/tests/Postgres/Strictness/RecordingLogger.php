<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres\Strictness;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Records what is logged, so a test can see the error a lazy load logs in production.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<array-key, mixed>}> */
    public array $records = [];

    /**
     * @param  array<array-key, mixed>  $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [is_string($level) ? $level : 'unknown', (string) $message, $context];
    }
}
