<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Idempotency;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreContract;
use Cbox\Cms\Testkit\Idempotency\IdempotencyStoreHarness;
use Closure;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The contract suite with the harness under test injected.
 */
final class InjectedIdempotencyStoreContract extends TestCase
{
    use IdempotencyStoreContract;

    /** @var (Closure(Clock): IdempotencyStoreHarness)|null */
    public ?Closure $harness = null;

    #[Override]
    protected function idempotencyStores(Clock $clock): IdempotencyStoreHarness
    {
        $harness = $this->harness ?? throw new LogicException('No harness was injected.');

        return $harness($clock);
    }
}
