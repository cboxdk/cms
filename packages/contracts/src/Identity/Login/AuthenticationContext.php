<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The acr claim (PRD 5.16): the authentication context class the identity provider says the login
 * met, often a URI: 1 to MAX_LENGTH visible ASCII characters, compared exactly.
 */
#[Experimental]
final readonly class AuthenticationContext
{
    public const int MAX_LENGTH = 255;

    private const string PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    /**
     * @throws InvalidIdentity when the value is not in the form
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::loginValue('authentication context', '1 to 255 visible ASCII characters');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
