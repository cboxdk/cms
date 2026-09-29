<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Events;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The SHA-256 of a text value, the only form in which text reaches an event (PRD 6.5 invariant 10,
 * 7.2). A subscriber compares hashes to see that a text changed without the event carrying it; it
 * reads the text itself from the state.
 *
 * It is held as 64 lowercase hex digits. Upper case hex is accepted and stored in lower case.
 */
#[Experimental]
final readonly class TextHash
{
    private const string PATTERN = '/\A[0-9a-f]{64}\z/i';

    public string $value;

    public function __construct(string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidEvent::textHash();
        }

        $this->value = strtolower($value);
    }

    /**
     * The hash of a text, its UTF-8 bytes as given.
     */
    public static function of(string $text): self
    {
        return new self(hash('sha256', $text));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
