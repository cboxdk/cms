<?php

declare(strict_types=1);

namespace Cbox\Cms\Identity\Sessions\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\SessionToken;
use InvalidArgumentException;

/**
 * What a session is stored under (PRD 5.16): the SHA-256 of its id as 64 lowercase hex digits. The
 * store never holds the id itself, so nothing it holds lets someone use a session.
 */
#[Internal]
final readonly class SessionKey
{
    private const string PATTERN = '/\A[0-9a-f]{64}\z/';

    /**
     * @throws InvalidArgumentException when the value is not 64 lowercase hex digits
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('A session key is the SHA-256 of a session id as 64 lowercase hex digits.');
        }
    }

    public static function of(SessionToken $token): self
    {
        return new self($token->hash());
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
