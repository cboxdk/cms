<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The claims of a logout token of OpenID Connect Back-Channel Logout 1.0 (PRD 5.16), after its
 * signature was verified against the issuer's keys: iss, aud, iat, exp, jti, events, sub and sid,
 * and whether the token carried a nonce at all. The implementation that verifies the signature
 * builds it from the token alone; a BackChannelLogoutReceiver then decides through
 * SignalPin::admitLogout().
 *
 * The form is checked here: at least one audience, each once, and an expiry after the time of
 * issue. Times are held in UTC. Whether the token is for the connection, still valid, holds the
 * logout event, names a subject or a session and carries no nonce is the receiver's rule, so the
 * refusal has a code.
 */
#[Experimental]
final readonly class LogoutToken
{
    public DateTimeImmutable $issuedAt;

    public DateTimeImmutable $expiresAt;

    /**
     * @param  list<Audience>  $audience  the aud claim, a string or a list of strings
     * @param  list<EventTypeUri>  $events  the keys of the events claim
     * @param  bool  $nonce  whether the token carried a nonce claim, whatever its value
     *
     * @throws InvalidIdentity when the audience is empty or names one twice, or the token expires at or before its issue
     */
    public function __construct(
        public Issuer $issuer,
        public array $audience,
        DateTimeImmutable $issuedAt,
        DateTimeImmutable $expiresAt,
        public SignalId $jti,
        public array $events,
        public ?Subject $subject,
        public ?IdpSessionId $session,
        public bool $nonce = false,
    ) {
        Audience::checkList($audience);

        if ($expiresAt <= $issuedAt) {
            throw InvalidIdentity::signalValue('expiry of a logout token', 'after its time of issue');
        }

        $utc = new DateTimeZone('UTC');
        $this->issuedAt = $issuedAt->setTimezone($utc);
        $this->expiresAt = $expiresAt->setTimezone($utc);
    }

    /**
     * Whether the events claim holds the back-channel logout event.
     */
    public function holdsLogoutEvent(): bool
    {
        $logout = EventTypeUri::backChannelLogout();

        return array_any($this->events, fn (EventTypeUri $event): bool => $event->equals($logout));
    }
}
