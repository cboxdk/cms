<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Core\Pipeline\Domain\CommandTransaction;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeCommandTransaction;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencySession;
use Cbox\Cms\Testkit\Idempotency\FakeIdempotencyStore;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * CommandTransactionBehaviour against the fake the pipeline's action tests use, over a session of
 * the testkit's fake idempotency store.
 */
final class FakeCommandTransactionBehaviourTest extends TestCase
{
    use CommandTransactionBehaviour;

    private ?FakeClock $clock = null;

    private ?FakeIdempotencySession $session = null;

    #[Override]
    protected function commandTransaction(): CommandTransaction
    {
        return new FakeCommandTransaction($this->session());
    }

    #[Override]
    protected function keys(): IdempotencyStore
    {
        return $this->session();
    }

    #[Override]
    protected function clock(): Clock
    {
        return $this->clock ??= new FakeClock;
    }

    #[Override]
    protected function openOutside(): void
    {
        $this->session()->begin();
    }

    #[Override]
    protected function closeOutside(): void
    {
        $this->session()->rollBack();
    }

    private function session(): FakeIdempotencySession
    {
        return $this->session ??= new FakeIdempotencyStore($this->clock())->session();
    }
}
