<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * An addon manifest, or a part of one, that breaks the rules of PRD 13.1 and 11.12. cms:build
 * reports it as registry_invalid_manifest, names the service provider that declared it and writes
 * nothing.
 */
#[Experimental]
final class InvalidAddonManifest extends InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
