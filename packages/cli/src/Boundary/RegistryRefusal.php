<?php

declare(strict_types=1);

namespace Cbox\Cms\Cli\Boundary;

use Cbox\Cms\Cli\Domain\CliCallRefused;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Core\Registry\Domain\MalformedRegistryCache;
use Cbox\Cms\Core\Registry\Domain\RegistryCacheMissing;

/**
 * A registry cache the cms:* commands that read the compiled registry cannot read, as the refusal
 * with its code of the error catalog: registry_cache_missing or registry_cache_malformed, both exit
 * 78, and the message that says to run cms:build.
 */
#[Internal]
final readonly class RegistryRefusal
{
    public static function of(RegistryCacheMissing|MalformedRegistryCache $failed): CliCallRefused
    {
        return CliCallRefused::catalog(
            $failed instanceof RegistryCacheMissing ? ErrorCode::RegistryCacheMissing : ErrorCode::RegistryCacheMalformed,
            null,
            $failed->getMessage(),
            $failed,
        );
    }
}
