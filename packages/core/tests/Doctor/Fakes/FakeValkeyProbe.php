<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor\Fakes;

use Cbox\Cms\Core\Doctor\Domain\ProbeFailed;
use Cbox\Cms\Core\Doctor\Domain\Probes\ValkeyProbe;

/**
 * A Valkey that answers PING until the test sets a failure.
 */
final class FakeValkeyProbe implements ValkeyProbe
{
    public function __construct(public ?ProbeFailed $failure = null) {}

    public function target(): string
    {
        return 'fake:6379 (Redis connection default)';
    }

    public function ping(): void
    {
        if ($this->failure instanceof ProbeFailed) {
            throw $this->failure;
        }
    }
}
