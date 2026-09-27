<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Storage;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * Tells a path PHP hands to the filesystem from one it hands to a stream wrapper (GUARDRAILS 3).
 *
 * PHP's file functions follow any registered wrapper: http:// and ftp:// fetch, write, rename,
 * remove and list across the network, and a package can register more. The kernel's local readers
 * and writers, in core and in the generators, refuse a path this names before any file function
 * sees it, so only the egress gateway reaches another machine. It lives in the contracts package
 * because core and the generators both use it and depend on nothing else in common.
 */
#[Internal]
final readonly class LocalPath
{
    /**
     * A path PHP hands to a stream wrapper instead of the filesystem: `scheme://` with the
     * characters PHP allows in a scheme (compress.zlib:// among them), or `data:`.
     */
    public const string STREAM_WRAPPER = '~\A(?:[A-Za-z0-9+.-]+://|data:)~i';

    private function __construct() {}

    /**
     * Whether PHP would hand the path to a stream wrapper, file:// included.
     */
    public static function namesStreamWrapper(string $path): bool
    {
        return preg_match(self::STREAM_WRAPPER, $path) === 1;
    }
}
