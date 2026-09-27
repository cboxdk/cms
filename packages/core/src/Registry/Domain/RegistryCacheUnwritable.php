<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * cms:build compiled the registry but could not write a cache file, or could not remove a file
 * the cache no longer writes. Files already written are complete, because each one is written to a
 * temporary file and renamed into place.
 */
#[Experimental]
final class RegistryCacheUnwritable extends RuntimeException
{
    public const string CODE = 'registry_cache_unwritable';

    public static function at(string $path, string $reason): self
    {
        return new self(sprintf(
            '[%s] Could not write the registry cache file %s: %s. Make the directory writable by the user that runs composer and php artisan, then run php artisan cms:build again.',
            self::CODE,
            $path,
            $reason,
        ));
    }

    public static function streamWrapper(string $directory): self
    {
        return new self(sprintf(
            '[%s] Could not write the registry cache to %s: the path names a stream wrapper, and the registry cache is written only to a local directory (GUARDRAILS 3). Give the application a local bootstrap path, then run php artisan cms:build again.',
            self::CODE,
            $directory,
        ));
    }

    public static function removing(string $path, string $reason): self
    {
        return new self(sprintf(
            '[%s] Could not remove %s from the registry cache, which owns its directory: %s. Remove the file, or make the directory writable by the user that runs composer and php artisan, then run php artisan cms:build again.',
            self::CODE,
            $path,
            $reason,
        ));
    }
}
