<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * A receiver refused a back-channel logout or a security event, for $reason. Nothing was ended or
 * changed. The message never holds a token, a subject or a claim's value, so it can be logged.
 */
#[Experimental]
final class SignalRefused extends RuntimeException
{
    private function __construct(public readonly SignalErrorCode $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function because(SignalErrorCode $reason): self
    {
        return new self($reason, sprintf('The signal was refused: %s.', match ($reason) {
            SignalErrorCode::IssuerMismatch => 'the token is from another issuer than the connection is pinned to',
            SignalErrorCode::AudienceMismatch => 'the token is not for the audience the connection is pinned to',
            SignalErrorCode::IssuedInFuture => 'the token was issued after the receiver\'s time',
            SignalErrorCode::Expired => 'the logout token has expired',
            SignalErrorCode::LogoutEventMissing => 'the logout token does not hold the back-channel logout event',
            SignalErrorCode::NoncePresent => 'the logout token carries a nonce',
            SignalErrorCode::SubjectMissing => 'the logout token names neither a subject nor an IdP session',
            SignalErrorCode::SubjectUnsupported => 'the event does not name its subject by the connection\'s issuer and a subject',
            SignalErrorCode::EventUnsupported => 'the event is of a type the core does not act on',
            SignalErrorCode::Replayed => 'the logout token was received before',
        }));
    }
}
