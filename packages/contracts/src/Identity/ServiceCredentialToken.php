<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use SensitiveParameter;

/**
 * The form of a service credential on the wire (PRD 5.16): 256 random bits and a checksum.
 *
 * A token is PREFIX, the 32 secret bytes as 64 lowercase hex digits, and the CRC-32 of the prefix
 * and those digits as 8 lowercase hex digits, 79 characters in all. The checksum lets a verifier
 * refuse a mistyped or truncated token, or a token of another system, without a lookup. A token
 * is shown once, when it is issued; it is stored only as hash(), the SHA-256 of the whole token.
 */
#[Experimental]
final readonly class ServiceCredentialToken
{
    public const string PREFIX = 'cms_sc_';

    public const int SECRET_BYTES = 32;

    private const string PATTERN = '/\Acms_sc_([0-9a-f]{64})([0-9a-f]{8})\z/';

    private function __construct(#[SensitiveParameter] private string $token) {}

    /**
     * The token for 32 secret bytes, which the issuer takes from a secure random source.
     *
     * @throws InvalidIdentity when the secret is not 32 bytes
     */
    public static function fromSecret(#[SensitiveParameter] string $secret): self
    {
        if (strlen($secret) !== self::SECRET_BYTES) {
            throw InvalidIdentity::secret(strlen($secret));
        }

        $body = self::PREFIX.bin2hex($secret);

        return new self($body.self::checksum($body));
    }

    /**
     * Reads a token from the transport. A credential not in the bearer form, anything that is not
     * in the form of a token, and a token whose checksum does not match are refused with
     * credential_malformed before any lookup.
     *
     * @throws CredentialRejected
     */
    public static function parse(TransportCredential $credential): self
    {
        $value = $credential->reveal();

        if ($credential->form !== CredentialForm::Bearer
            || preg_match(self::PATTERN, $value, $parts) !== 1
            || ! hash_equals(self::checksum(self::PREFIX.$parts[1]), $parts[2])) {
            throw CredentialRejected::because(CredentialErrorCode::Malformed);
        }

        return new self($value);
    }

    /**
     * The SHA-256 of the token as 64 lowercase hex digits: what a store keeps and looks up.
     */
    public function hash(): string
    {
        return hash('sha256', $this->token);
    }

    public function credential(): TransportCredential
    {
        return new TransportCredential($this->token);
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
