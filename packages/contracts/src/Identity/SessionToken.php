<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use SensitiveParameter;

/**
 * The id of a session on the wire (PRD 5.16): 256 random bits and a checksum, carried in the
 * session cookie as the session form of a TransportCredential.
 *
 * A token is PREFIX, the 32 secret bytes as 64 lowercase hex digits, and the CRC-32 of the prefix
 * and those digits as 8 lowercase hex digits, 79 characters in all, as a ServiceCredentialToken is
 * with its own prefix. The checksum lets a verifier refuse a mistyped or truncated id, or a value
 * of another system, without a lookup. A session is stored only under hash(), the SHA-256 of the
 * whole token, so the store never holds an id that would let someone use it.
 */
#[Experimental]
final readonly class SessionToken
{
    public const string PREFIX = 'cms_ss_';

    public const int SECRET_BYTES = 32;

    private const string PATTERN = '/\Acms_ss_([0-9a-f]{64})([0-9a-f]{8})\z/';

    private function __construct(#[SensitiveParameter] private string $token) {}

    /**
     * The token for 32 secret bytes, which the issuer takes from a secure random source.
     *
     * @throws InvalidIdentity when the secret is not 32 bytes
     */
    public static function fromSecret(#[SensitiveParameter] string $secret): self
    {
        if (strlen($secret) !== self::SECRET_BYTES) {
            throw InvalidIdentity::sessionSecret(strlen($secret));
        }

        $body = self::PREFIX.bin2hex($secret);

        return new self($body.self::checksum($body));
    }

    /**
     * Reads a session id from the transport. A credential not in the session form, or not in the
     * form of a token, or whose checksum does not match, is refused with credential_malformed
     * before any lookup.
     *
     * @throws CredentialRejected
     */
    public static function parse(TransportCredential $credential): self
    {
        $value = $credential->reveal();

        if ($credential->form !== CredentialForm::Session
            || preg_match(self::PATTERN, $value, $parts) !== 1
            || ! hash_equals(self::checksum(self::PREFIX.$parts[1]), $parts[2])) {
            throw CredentialRejected::because(CredentialErrorCode::Malformed);
        }

        return new self($value);
    }

    /**
     * The SHA-256 of the token as 64 lowercase hex digits: what a store keeps a session under.
     */
    public function hash(): string
    {
        return hash('sha256', $this->token);
    }

    /**
     * The token in the session form, as the session cookie carries it.
     */
    public function credential(): TransportCredential
    {
        return TransportCredential::session($this->token);
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
