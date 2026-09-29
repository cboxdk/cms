<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Cdn;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A purge or a purge result that is not in its form.
 */
#[Experimental]
final class InvalidCdnPurge extends InvalidArgumentException
{
    public static function noKeys(): self
    {
        return new self('A CDN purge names at least one surrogate key.');
    }

    public static function requests(int $requests): self
    {
        return new self(sprintf('A purge takes at least one request, got %d.', $requests));
    }
}
