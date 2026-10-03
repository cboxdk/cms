<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * The path of a file of an addon's panel bundle, relative to the bundle's directory (PRD 13.4):
 * segments separated by slashes, each of ASCII letters, digits, dots, hyphens and underscores and
 * not starting with a dot, so a path never leaves the directory, names a hidden file or a stream
 * wrapper; at most 255 characters.
 */
#[Experimental]
final readonly class BundlePath
{
    public const string PATTERN = '/\A[A-Za-z0-9_][A-Za-z0-9._-]*(?:\/[A-Za-z0-9_][A-Za-z0-9._-]*)*\z/';

    public const int MAX_LENGTH = 255;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > self::MAX_LENGTH || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not the path of a file in a panel bundle: slash-separated segments of letters, digits, dots, hyphens and underscores, none starting with a dot, of at most %d characters.', $value, self::MAX_LENGTH));
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
