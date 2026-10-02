<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Provisioning;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The userName of a SCIM user (RFC 7643 4.1.1): 1 to MAX_LENGTH characters without control
 * characters, unique within its connection without regard to case. It is what the identity provider
 * shows; it is personal data and never the key to an actor, which is the user's externalId.
 */
#[Experimental]
final readonly class ScimUserName
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[^\s\x00-\x1F\x7F](?:[^\x00-\x1F\x7F]*[^\s\x00-\x1F\x7F])?\z/u';

    /**
     * @throws InvalidIdentity when the value is not in the form; the message never repeats it
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1 || mb_strlen($value, 'UTF-8') > self::MAX_LENGTH) {
            throw InvalidIdentity::signalValue('SCIM userName', '1 to 255 characters without control characters, starting and ending with one that is not white space');
        }
    }

    /**
     * Whether the two are the same userName for uniqueness, without regard to case.
     */
    public function sameAs(self $other): bool
    {
        return mb_strtolower($this->value, 'UTF-8') === mb_strtolower($other->value, 'UTF-8');
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
