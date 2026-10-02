<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * One value of the amr claim (PRD 5.16, RFC 8176): a method the person authenticated with, such
 * as pwd, otp, hwk or mfa. Identity providers send values RFC 8176 does not list, so it is not an
 * enum: 1 to MAX_LENGTH visible ASCII characters, compared exactly.
 */
#[Experimental]
final readonly class AuthenticationMethod
{
    public const int MAX_LENGTH = 64;

    private const string PATTERN = '/\A[\x21-\x7E]{1,64}\z/';

    /**
     * @throws InvalidIdentity when the value is not in the form
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::loginValue('authentication method', '1 to 64 visible ASCII characters');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
