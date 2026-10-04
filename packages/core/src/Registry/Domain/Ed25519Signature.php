<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;
use LogicException;

/**
 * An Ed25519 signature (RFC 8032): the base64 of its 64 bytes, as panel-signature.json carries
 * the publisher's signature over panel-manifest.json (PRD 13.8).
 */
#[Experimental]
final readonly class Ed25519Signature
{
    public const string PATTERN = '/\A[A-Za-z0-9+\/]{86}==\z/';

    /** The length of an Ed25519 signature in bytes. */
    public const int BYTES = 64;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1 || strlen((string) base64_decode($value, true)) !== self::BYTES) {
            throw new InvalidArgumentException(sprintf('"%s" is not an Ed25519 signature: the 88 base64 characters of its 64 bytes.', $value));
        }
    }

    /**
     * The signature's 64 bytes.
     *
     * @return non-empty-string
     */
    public function bytes(): string
    {
        $bytes = base64_decode($this->value, true);

        return $bytes === false || $bytes === '' ? throw new LogicException(sprintf('The signature "%s" passed its constructor and does not decode.', $this->value)) : $bytes;
    }

    /**
     * Whether the signature is the key's signature over the message.
     */
    public function verifies(string $message, PublisherKey $key): bool
    {
        return sodium_crypto_sign_verify_detached($this->bytes(), $message, $key->bytes());
    }
}
