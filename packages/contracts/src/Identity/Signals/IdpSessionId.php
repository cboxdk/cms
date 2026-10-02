<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Signals;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The id of a session at the identity provider (PRD 5.16), the sid claim of OpenID Connect: 1 to
 * MAX_LENGTH visible ASCII characters, compared exactly. A CMS session stores the sid of the login
 * that made it, and the sessions of one (connection, sid) are kept in a set, so a back-channel
 * logout for the sid ends exactly those sessions.
 */
#[Experimental]
final readonly class IdpSessionId
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    /**
     * @throws InvalidIdentity when the value is not an IdP session id
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::signalValue('an IdP session id', '1 to 255 visible ASCII characters');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
