<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\Login\ConnectionId;
use Cbox\Cms\Contracts\Identity\Login\IdpIdentity;
use Cbox\Cms\Contracts\Identity\Login\Issuer;
use Cbox\Cms\Contracts\Identity\Login\Subject;
use DateInterval;
use DateTimeImmutable;

/**
 * What a connection takes signals from (PRD 5.16): the issuer it is pinned to, the same as its
 * login's, and the audience the CMS has there, the client id for a logout token or the stream's
 * audience for a security event. Every receiver decides through admitLogout() and admitEvent(), so
 * every receiver applies the same rules in the same order; remembering the jti is the receiver's.
 */
#[Experimental]
final readonly class SignalPin
{
    /** How far the clocks of the identity provider and the receiver may differ, in seconds. */
    public const int CLOCK_SKEW_SECONDS = 60;

    public function __construct(public ConnectionId $connection, public Issuer $issuer, public Audience $audience) {}

    /**
     * The sessions a verified logout token ends, at the receiver's time $now. The checks run in
     * this order: issuer, audience, time of issue, expiry, the logout event, the nonce, and a
     * subject or session.
     *
     * @throws SignalRefused when the token breaks a rule
     */
    public function admitLogout(LogoutToken $token, DateTimeImmutable $now): LogoutOutcome
    {
        $this->admitToken($token->issuer, $token->audience, $token->issuedAt, $now);

        if ($token->expiresAt->add($this->skew()) <= $now) {
            throw SignalRefused::because(SignalErrorCode::Expired);
        }

        if (! $token->holdsLogoutEvent()) {
            throw SignalRefused::because(SignalErrorCode::LogoutEventMissing);
        }

        if ($token->nonce) {
            throw SignalRefused::because(SignalErrorCode::NoncePresent);
        }

        if (! $token->subject instanceof Subject && ! $token->session instanceof IdpSessionId) {
            throw SignalRefused::because(SignalErrorCode::SubjectMissing);
        }

        return new LogoutOutcome($this->connection, $this->issuer, $token->jti, $token->subject, $token->session);
    }

    /**
     * What a verified security event token asks for, at the receiver's time $now. The checks run in
     * this order: issuer, audience, time of issue, the event's type, and the subject, which must be
     * an iss_sub of the pinned issuer (RFC 9493), never an email address alone.
     *
     * @throws SignalRefused when the token breaks a rule
     */
    public function admitEvent(SecurityEventToken $token, DateTimeImmutable $now): SecurityEventApplied
    {
        $this->admitToken($token->issuer, $token->audience, $token->issuedAt, $now);

        $kind = SecurityEventKind::of($token->event) ?? throw SignalRefused::because(SignalErrorCode::EventUnsupported);
        $subject = $token->subject;

        if ($subject->format !== SubjectFormat::IssSub
            || ! $subject->issuer instanceof Issuer
            || ! $subject->subject instanceof Subject
            || ! $subject->issuer->equals($this->issuer)) {
            throw SignalRefused::because(SignalErrorCode::SubjectUnsupported);
        }

        return new SecurityEventApplied($this->connection, $token->jti, $kind, new IdpIdentity($this->connection, $this->issuer, $subject->subject));
    }

    /**
     * @param  list<Audience>  $audience
     *
     * @throws SignalRefused
     */
    private function admitToken(Issuer $issuer, array $audience, DateTimeImmutable $issuedAt, DateTimeImmutable $now): void
    {
        if (! $issuer->equals($this->issuer)) {
            throw SignalRefused::because(SignalErrorCode::IssuerMismatch);
        }

        if (! $this->audience->isListedIn($audience)) {
            throw SignalRefused::because(SignalErrorCode::AudienceMismatch);
        }

        if ($issuedAt > $now->add($this->skew())) {
            throw SignalRefused::because(SignalErrorCode::IssuedInFuture);
        }
    }

    private function skew(): DateInterval
    {
        return new DateInterval(sprintf('PT%dS', self::CLOCK_SKEW_SECONDS));
    }
}
