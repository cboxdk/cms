<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The shapes of the opaque text values of an envelope.
 */
#[Internal]
final readonly class EnvelopeText
{
    private const string VISIBLE_ASCII = '/\A[\x21-\x7E]+\z/';

    /**
     * 1 to $maxBytes visible ASCII characters (0x21 to 0x7E), as ids and keys are.
     */
    public static function isToken(string $value, int $maxBytes): bool
    {
        return strlen($value) <= $maxBytes && preg_match(self::VISIBLE_ASCII, $value) === 1;
    }

    /**
     * 1 to $maxBytes bytes of UTF-8 with no control character, as names and references are.
     */
    public static function isLine(string $value, int $maxBytes): bool
    {
        return $value !== ''
            && strlen($value) <= $maxBytes
            && mb_check_encoding($value, 'UTF-8')
            && preg_match('/\p{Cc}/u', $value) === 0;
    }
}
