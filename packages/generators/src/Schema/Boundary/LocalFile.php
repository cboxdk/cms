<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use RuntimeException;
use SplFileObject;

/**
 * Reads a local file, and never a URL (GUARDRAILS 3). The generators read every schema and
 * generated file through it.
 *
 * Not file_get_contents, which is reserved for the egress gateway. SplFileObject, is_file() and
 * the other file functions go through PHP's stream wrappers too, which fetch http:// and ftp://
 * URLs and follow any wrapper a package registers, so a path that names a wrapper is refused
 * before any of them sees it. The Arch suite allows SplFileObject here because of that (Egress).
 */
#[Internal]
final readonly class LocalFile
{
    /**
     * A path PHP hands to a stream wrapper instead of the filesystem: `scheme://` with the
     * characters PHP allows in a scheme (compress.zlib:// among them), or `data:`.
     */
    public const string WRAPPER = '~\A(?:[A-Za-z0-9+.-]+://|data:)~i';

    /**
     * The contents of the file, or null when it is not a readable regular file or the path names a
     * stream wrapper.
     */
    public static function contents(string $path): ?string
    {
        if (preg_match(self::WRAPPER, $path) === 1 || ! is_file($path) || ! is_readable($path)) {
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
