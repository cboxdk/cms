<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity\Login;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Identity\InvalidIdentity;

/**
 * The name of a login connection (PRD 5.16), as the environment's configuration names it, such as
 * "local", "google" or "entra-acme": a lowercase letter, then lowercase letters, digits, hyphens and
 * underscores, at most MAX_LENGTH characters. It is the first part of an IdP identity.
 */
#[Experimental]
final readonly class ConnectionId
{
    public const int MAX_LENGTH = 64;

    private const string PATTERN = '/\A[a-z][a-z0-9_-]{0,63}\z/';

    /**
     * @throws InvalidIdentity when the name is not in the form
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdentity::loginValue('connection id', 'a lowercase letter, then at most 63 lowercase letters, digits, hyphens and underscores');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
