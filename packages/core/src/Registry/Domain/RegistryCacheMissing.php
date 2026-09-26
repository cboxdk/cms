<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;

/**
 * The application read the registry, but a cache file is not there. The cache is not committed
 * (PRD 13.2); composer's post-autoload-dump and the deploy build it.
 */
#[Experimental]
final class RegistryCacheMissing extends RuntimeException
{
    public const string CODE = 'registry_cache_missing';

    public static function at(string $path): self
    {
        return new self(sprintf(
            '[%s] The registry cache file %s does not exist. Run php artisan cms:build, which composer dump-autoload also runs, and make the deploy run it.',
            self::CODE,
            $path,
        ));
    }
}
