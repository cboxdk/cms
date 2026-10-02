<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use InvalidArgumentException;

/**
 * The nonce of one panel response's Content-Security-Policy (GUARDRAILS 6): 16 random bytes in
 * base64, new for every response, so a script or style the response did not name itself never
 * runs. The policy names it as `'nonce-<value>'`, and the root view puts it on the panel's script,
 * stylesheets and module preloads.
 */
#[Internal]
final readonly class CspNonce
{
    /** The random bytes of a nonce: 128 bits, as the CSP specification asks for at least. */
    public const int BYTES = 16;

    /** The base64 form of BYTES random bytes. */
    public const string PATTERN = '/\A[A-Za-z0-9+\/]{22}==\z/';

    /**
     * @throws InvalidArgumentException when the value is not the base64 form of 16 bytes
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('A CSP nonce is 16 bytes in base64, 24 characters ending in "==".');
        }
    }

    /**
     * A new nonce from the system's cryptographically secure random source.
     */
    public static function random(): self
    {
        return new self(base64_encode(random_bytes(self::BYTES)));
    }
}
