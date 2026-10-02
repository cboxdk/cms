<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Signals\BackChannelLogoutReceiver;
use Cbox\Cms\Contracts\Identity\Signals\LogoutOutcome;
use Cbox\Cms\Contracts\Identity\Signals\LogoutToken;
use Cbox\Cms\Contracts\Identity\Signals\SignalErrorCode;
use Cbox\Cms\Contracts\Identity\Signals\SignalPin;
use Cbox\Cms\Contracts\Identity\Signals\SignalRefused;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Override;

/**
 * The in-memory fake of BackChannelLogoutReceiver (GUARDRAILS 2.3), and its own harness. It holds
 * its pins, decides through SignalPin::admitLogout() at its clock's time and remembers each admitted
 * issuer and jti for as long as it lives, so a replay is refused. The real receiver, with its replay
 * store in Valkey, comes with the OpenID Connect implementation.
 */
#[Experimental]
final class FakeBackChannelLogoutReceiver implements BackChannelLogoutHarness, BackChannelLogoutReceiver
{
    private readonly SignalPins $pins;

    /** @var array<string, true> by issuer and jti */
    private array $seen = [];

    public function __construct(private readonly Clock $clock = new FakeClock, SignalPin ...$pins)
    {
        $this->pins = new SignalPins(...$pins);
    }

    #[Override]
    public function receiver(Clock $clock, SignalPin ...$pins): BackChannelLogoutReceiver
    {
        return new self($clock, ...$pins);
    }

    #[Override]
    public function receive(ConnectionId $connection, LogoutToken $token): LogoutOutcome
    {
        $outcome = $this->pins->of($connection)->admitLogout($token, $this->clock->now());
        $key = $token->issuer->value."\n".$token->jti->value;

        if (isset($this->seen[$key])) {
            throw SignalRefused::because(SignalErrorCode::Replayed);
        }

        $this->seen[$key] = true;

        return $outcome;
    }
}
