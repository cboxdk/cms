<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;
use LogicException;

/**
 * An addon publisher's Ed25519 public key (PRD 13.8): the base64 of its 32 bytes, as
 * panel-signature.json carries it and cbox-cms.addons.publishers names it.
 */
#[Experimental]
final readonly class PublisherKey
{
    public const string PATTERN = '/\A[A-Za-z0-9+\/]{43}=\z/';

    /** The length of an Ed25519 public key in bytes. */
    public const int BYTES = 32;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1 || strlen((string) base64_decode($value, true)) !== self::BYTES) {
            throw new InvalidArgumentException(sprintf('"%s" is not an Ed25519 public key: the 44 base64 characters of its 32 bytes.', $value));
        }
    }

    /**
     * The key's 32 bytes.
     *
     * @return non-empty-string
     */
    public function bytes(): string
    {
        $bytes = base64_decode($this->value, true);

        return $bytes === false || $bytes === '' ? throw new LogicException(sprintf('The key "%s" passed its constructor and does not decode.', $this->value)) : $bytes;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
