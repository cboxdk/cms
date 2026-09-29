<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cdn;

use Cbox\Cms\Contracts\Attributes\Experimental;
use RuntimeException;
use Throwable;

/**
 * The CDN did not take a purge: it did not answer, refused the credentials of the worker, or
 * answered with an error. Some of the purge's requests may have been applied. A purge is
 * idempotent, so the caller retries the whole purge later.
 */
#[Experimental]
final class CdnUnavailable extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('The CDN did not take the purge: %s', $reason), 0, $previous);
    }
}
