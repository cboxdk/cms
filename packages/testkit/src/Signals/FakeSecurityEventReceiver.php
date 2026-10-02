<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventOutcome;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventReceiver;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventReplayed;
use Cbox\Cms\Contracts\Identity\Signals\SecurityEventToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Override;

/**
 * The in-memory fake of SecurityEventReceiver (GUARDRAILS 2.3), and its own harness. It holds its
 * pins, decides through SignalPin::admitEvent() at its clock's time and remembers each admitted
 * issuer and jti for as long as it lives, so a later delivery of one is SecurityEventReplayed. The
 * real receiver comes with B6.
 */
#[Experimental]
final class FakeSecurityEventReceiver implements SecurityEventHarness, SecurityEventReceiver
{
    private readonly SignalPins $pins;

    /** @var array<string, true> by issuer and jti */
    private array $seen = [];

    public function __construct(private readonly Clock $clock = new FakeClock, SignalPin ...$pins)
    {
        $this->pins = new SignalPins(...$pins);
    }

    #[Override]
    public function receiver(Clock $clock, SignalPin ...$pins): SecurityEventReceiver
    {
        return new self($clock, ...$pins);
    }

    #[Override]
    public function receive(ConnectionId $connection, SecurityEventToken $token): SecurityEventOutcome
    {
        $applied = $this->pins->of($connection)->admitEvent($token, $this->clock->now());
        $key = $token->issuer->value."\n".$token->jti->value;

        if (isset($this->seen[$key])) {
            return new SecurityEventReplayed($connection, $token->jti);
        }

        $this->seen[$key] = true;

        return $applied;
    }
}
