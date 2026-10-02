<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The claims of a security event token (RFC 8417) of the OpenID Shared Signals Framework 1.0
 * (PRD 5.16), delivered by push (RFC 8935) or poll (RFC 8936), after its signature was verified
 * against the transmitter's keys: iss, aud, iat, jti, its one event's type and the subject the
 * event names (RFC 9493). The implementation that verifies the signature builds it from the token
 * alone, and refuses a token with more or fewer than one event, as SSF 1.0 requires one; a
 * SecurityEventReceiver then decides through SignalPin::admitEvent().
 *
 * The form is checked here: at least one audience, each once. The time of issue is held in UTC.
 */
#[Experimental]
final readonly class SecurityEventToken
{
    public DateTimeImmutable $issuedAt;

    /**
     * @param  list<Audience>  $audience  the aud claim, a string or a list of strings
     *
     * @throws InvalidIdentity when the audience is empty or names one twice
     */
    public function __construct(
        public Issuer $issuer,
        public array $audience,
        DateTimeImmutable $issuedAt,
        public SignalId $jti,
        public EventTypeUri $event,
        public SubjectIdentifier $subject,
    ) {
        Audience::checkList($audience);

        $this->issuedAt = $issuedAt->setTimezone(new DateTimeZone('UTC'));
    }
}
