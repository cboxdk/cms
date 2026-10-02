<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Tests\Sessions;

use Cbox\Cms\Identity\Sessions\Domain\SessionStore;
use Cbox\Cms\Identity\Tests\Sessions\Fakes\FakeSessionStore;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateInterval;
use DateTimeImmutable;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * SessionStoreBehaviour against the fake the session tests use, and what only a clock that moves
 * shows: a session is kept until the end it was put or last touched with, and no longer.
 */
final class FakeSessionStoreBehaviourTest extends TestCase
{
    use SessionStoreBehaviour;

    private ?FakeClock $clock = null;

    #[Override]
    protected function sessionStore(): SessionStore
    {
        return new FakeSessionStore($this->clock());
    }

    #[Override]
    protected function now(): DateTimeImmutable
    {
        return $this->clock()->now();
    }

    public function test_it_drops_a_session_at_the_end_it_was_put_or_touched_with(): void
    {
        $store = new FakeSessionStore($this->clock());
        $session = $this->storedSession();
        $store->put($session, $this->now()->modify('+10 minutes'));

        $this->clock()->advance(new DateInterval('PT9M'));
        self::assertTrue($store->touch($session->seenAt($this->now()), $this->now()->modify('+10 minutes')));

        $this->clock()->advance(new DateInterval('PT9M'));
        self::assertNotNull($store->find($session->key));

        $this->clock()->advance(new DateInterval('PT1M'));
        self::assertNull($store->find($session->key));
        self::assertSame(0, $store->count());
        self::assertSame(0, $store->endActor($session->actor));
    }

    private function clock(): FakeClock
    {
        return $this->clock ??= new FakeClock(new DateTimeImmutable('2026-10-02T09:00:00Z'));
    }
}
