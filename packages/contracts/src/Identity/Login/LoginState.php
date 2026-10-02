<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;
use SensitiveParameter;

/**
 * The state of a pending login (PRD 5.16): an unguessable value the connection makes when the
 * login starts, at least 128 random bits written as MIN_LENGTH to MAX_LENGTH characters of
 * base64url. It goes with the login form, or as the state parameter to the identity provider, and
 * the response must carry it back, so a response cannot be planted into another person's login.
 */
#[Experimental]
final readonly class LoginState
{
    public const int MIN_LENGTH = 22;

    public const int MAX_LENGTH = 128;

    private const string PATTERN = '/\A[A-Za-z0-9_-]{22,128}\z/';

    /**
     * @throws InvalidIdentity when the value is not a state
     */
    public function __construct(#[SensitiveParameter] public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::loginValue('login state', '22 to 128 characters of base64url');
        }
    }

    /**
     * A state of the given random bytes, at least 16, which the connection takes from a secure
     * random source.
     *
     * @throws InvalidIdentity when there are fewer than 16 bytes
     */
    public static function fromRandomBytes(#[SensitiveParameter] string $bytes): self
    {
        if (strlen($bytes) < 16 || strlen($bytes) > 96) {
            throw InvalidIdentity::loginValue('random bytes of a login state', '16 to 96 bytes');
        }

        return new self(rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='));
    }

    /**
     * Whether the text is this state, compared in constant time.
     */
    public function matches(#[SensitiveParameter] string $text): bool
    {
        return hash_equals($this->value, $text);
    }
}
