<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * The SHA-384 of a file of an addon's panel bundle in the form of Subresource Integrity, `sha384-`
 * and the base64 of the digest, as the import map's integrity entries carry it (PRD 13.4).
 */
#[Experimental]
final readonly class BundleIntegrity
{
    public const string PATTERN = '/\Asha384-[A-Za-z0-9+\/]{64}\z/';

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a SHA-384 in the form of Subresource Integrity: "sha384-" and the 64 base64 characters of the digest.', $value));
        }
    }

    /**
     * The integrity of the bytes.
     */
    public static function of(string $bytes): self
    {
        return new self('sha384-'.base64_encode(hash('sha384', $bytes, true)));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
