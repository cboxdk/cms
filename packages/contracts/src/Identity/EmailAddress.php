<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The contact email address of an actor's profile (PRD 5.16), where the installation writes to
 * the person, or to the person responsible for a service. It is personal data, classified personal
 * (PRD 12.2): it lives in the actor's profile, never in an event or the audit, and no message
 * repeats it. It is never the key of an identity: an identity provider's identity is never linked
 * to an actor by email alone (PRD 5.16).
 *
 * It is a local part, an @ and a domain with at least one dot, without white space or control
 * characters, at most MAX_LENGTH characters, as PATTERN says. It is kept as given and compared
 * exactly; whether the address receives mail is not checked here.
 */
#[Experimental]
final readonly class EmailAddress
{
    /** The longest address, in characters (RFC 5321's limit on a path, less its brackets). */
    public const int MAX_LENGTH = 254;

    /** The form of an address, as the JSON Schema of actor.register states it. */
    public const string PATTERN = '^[^\s@\x00-\x1F\x7F]+@[^\s@\x00-\x1F\x7F]+\.[^\s@\x00-\x1F\x7F]+$';

    public function __construct(public string $value)
    {
        if (
            preg_match('/'.self::PATTERN.'/u', $value) !== 1
            || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH
        ) {
            throw InvalidIdentity::email();
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
