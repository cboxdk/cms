<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;
use Throwable;

/**
 * A registry cache file exists but does not hold a registry this version of the core can read:
 * it was edited by hand, damaged, or written by another version of cms:build.
 */
#[Experimental]
final class MalformedRegistryCache extends RuntimeException
{
    public const string CODE = 'registry_cache_malformed';

    /**
     * @param  string  $at  where in the file, such as "entries[0].surfaces[1]"; empty for the whole file
     */
    public static function at(string $path, string $at, string $problem, ?Throwable $previous = null): self
    {
        return new self(sprintf(
            '[%s] The registry cache file %s is not valid%s: %s. Do not edit the cache; run php artisan cms:build to write it again.',
            self::CODE,
            $path,
            $at === '' ? '' : sprintf(' at %s', $at),
            $problem,
        ), 0, $previous);
    }
}
