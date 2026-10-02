<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use SensitiveParameter;

/**
 * The token of a password reset (PRD 5.16): 256 random bits and a checksum, which the person gets
 * in a link and which sets a new password once.
 *
 * A token is PREFIX, the 32 secret bytes as 64 lowercase hex digits, and the CRC-32 of the prefix
 * and those digits as 8 lowercase hex digits, 79 characters in all, as a SessionToken is with its
 * own prefix. A store keeps only hash(), the SHA-256 of the whole token, never the token. It is
 * secret: reveal() gives it to the code that builds the link, and var_dump() and a stack trace
 * never show it.
 */
#[Experimental]
final readonly class PasswordResetToken
{
    public const string PREFIX = 'cms_pr_';

    public const int SECRET_BYTES = 32;

    private const string PATTERN = '/\Acms_pr_([0-9a-f]{64})([0-9a-f]{8})\z/';

    private function __construct(#[SensitiveParameter] private string $token) {}

    /**
     * The token for 32 secret bytes, which the store takes from a secure random source.
     *
     * @throws InvalidIdentity when the secret is not 32 bytes
     */
    public static function fromSecret(#[SensitiveParameter] string $secret): self
    {
        if (strlen($secret) !== self::SECRET_BYTES) {
            throw InvalidIdentity::resetTokenSecret(strlen($secret));
        }

        $body = self::PREFIX.bin2hex($secret);

        return new self($body.self::checksum($body));
    }

    /**
     * Reads a token from a link, or gives null when the text is not in the form of a token or its
     * checksum does not match, before any lookup.
     */
    public static function parse(#[SensitiveParameter] string $text): ?self
    {
        if (preg_match(self::PATTERN, $text, $parts) !== 1 || ! hash_equals(self::checksum(self::PREFIX.$parts[1]), $parts[2])) {
            return null;
        }

        return new self($text);
    }

    /**
     * The token itself, for the link that carries it. Never log or store it.
     */
    public function reveal(): string
    {
        return $this->token;
    }

    /**
     * The SHA-256 of the token as 64 lowercase hex digits: what a store keeps the token under.
     */
    public function hash(): string
    {
        return hash('sha256', $this->token);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => '[secret]'];
    }

    private static function checksum(string $body): string
    {
        return hash('crc32b', $body);
    }
}
