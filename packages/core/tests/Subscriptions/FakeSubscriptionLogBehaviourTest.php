<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Events\EventStream;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Subscribers\SubscriptionName;
use Cbox\Cms\Core\Subscriptions\Domain\SubscriptionLog;
use Cbox\Cms\Core\Tests\Subscriptions\Fakes\FakeSubscriptionLog;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SubscriptionLogBehaviour against the fake the runner's action tests use.
 */
final class FakeSubscriptionLogBehaviourTest extends TestCase
{
    use SubscriptionLogBehaviour;

    private ?FakeSubscriptionLog $log = null;

    private ?SubscriptionName $held = null;

    #[Override]
    protected function subscriptionLog(FakeClock $clock): SubscriptionLog
    {
        $log = $this->log ??= new FakeSubscriptionLog($clock);

        if ($this->held instanceof SubscriptionName) {
            $log->hold($this->held);
        }

        return $log;
    }

    #[Override]
    protected function commitEvents(EventStream $stream, array $events): array
    {
        return $this->fake()->record($stream, $events);
    }

    #[Override]
    protected function holdLock(SubscriptionName $subscription): void
    {
        $this->held = $subscription;
        $this->fake()->hold($subscription);
    }

    #[Override]
    protected function freeLock(): void
    {
        if ($this->held instanceof SubscriptionName) {
            $this->fake()->free($this->held);
        }

        $this->held = null;
    }

    #[Override]
    protected function actorInBatch(SubscriptionLog $log): ?string
    {
        $principal = $this->fake()->context()?->principal;

        return $principal instanceof ActorPrincipal ? $principal->actor->toString() : null;
    }

    private function fake(): FakeSubscriptionLog
    {
        return $this->log ??= new FakeSubscriptionLog(new FakeClock);
    }
}
