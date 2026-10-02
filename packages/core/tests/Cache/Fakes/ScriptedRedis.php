<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Cache\Fakes;

use Override;
use Redis;

/**
 * A phpredis client that never connects: eval() records each call and gives the next scripted
 * answer, and the last error is kept as phpredis keeps it, until clearLastError(). An answer of
 * false with an error is a script that failed on the server.
 */
final class ScriptedRedis extends Redis
{
    /** @var list<ScriptCall> */
    public array $calls = [];

    /**
     * @param  list<mixed>  $answers
     */
    public function __construct(
        private array $answers = [],
        private readonly string $prefix = '',
        private ?string $lastError = null,
        private readonly ?string $failWith = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $args
     */
    #[Override]
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->calls[] = new ScriptCall($script, array_values($args), $num_keys);

        if ($this->failWith !== null) {
            $this->lastError = $this->failWith;

            return false;
        }

        return array_shift($this->answers);
    }

    #[Override]
    public function _prefix(string $key): string
    {
        return $this->prefix.$key;
    }

    #[Override]
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    #[Override]
    public function clearLastError(): bool
    {
        $this->lastError = null;

        return true;
    }
}
