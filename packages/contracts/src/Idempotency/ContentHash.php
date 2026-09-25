<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The hash of a command's content, stored with its idempotency key (PRD 6.1). The same key with
 * another hash is an idempotency_conflict.
 *
 * The hash is SHA-256, held as 64 lowercase hex digits. Upper case hex is accepted and stored in
 * lower case. Which bytes are hashed, the command's canonical form, is fixed by the command codecs
 * in M1.
 */
#[Experimental]
final readonly class ContentHash
{
    private const string PATTERN = '/\A[0-9a-f]{64}\z/i';

    public string $value;

    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidIdempotencyValue::contentHash($value);
        }

        $this->value = strtolower($value);
    }

    /**
     * The SHA-256 of the given bytes.
     */
    public static function of(string $content): self
    {
        return new self(hash('sha256', $content));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
