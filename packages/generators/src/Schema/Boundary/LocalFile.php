<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use RuntimeException;
use SplFileObject;

/**
 * Reads a local file. Not file_get_contents, which is reserved for the egress gateway because it
 * also fetches URLs.
 */
#[Internal]
final readonly class LocalFile
{
    /**
     * The contents of the file, or null when it is not a readable regular file.
     */
    public static function contents(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $file = new SplFileObject($path, 'rb');
            $size = $file->getSize();
            $contents = $size === 0 || $size === false ? '' : $file->fread($size);
        } catch (RuntimeException) {
            return null;
        }

        return is_string($contents) ? $contents : null;
    }
}
