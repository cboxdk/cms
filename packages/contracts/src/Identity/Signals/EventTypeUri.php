<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The type of an event in a signed signal (PRD 5.16): a key of the events claim of a logout token or
 * a security event token (RFC 8417), such as the back-channel logout event BACKCHANNEL_LOGOUT or a
 * case of SecurityEventKind. An absolute URI of 1 to MAX_LENGTH visible ASCII characters, compared
 * exactly. A receiver takes any type in this form; which types it acts on is the receiver's rule.
 */
#[Experimental]
final readonly class EventTypeUri
{
    public const int MAX_LENGTH = 255;

    /** The event of a logout token, in OpenID Connect Back-Channel Logout 1.0. */
    public const string BACKCHANNEL_LOGOUT = 'http://schemas.openid.net/event/backchannel-logout';

    private const string PATTERN = '/\A[a-z][a-z0-9+.-]*:[\x21-\x7E]+\z/';

    /**
     * @throws InvalidIdentity when the value is not an absolute URI in the form
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::signalValue('event type', 'an absolute URI of at most 255 visible ASCII characters');
        }
    }

    public static function backChannelLogout(): self
    {
        return new self(self::BACKCHANNEL_LOGOUT);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
