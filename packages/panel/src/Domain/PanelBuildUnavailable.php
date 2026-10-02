<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use RuntimeException;
use Throwable;

/**
 * The panel's build cannot be used: its Vite manifest is missing, unreadable or not a manifest of
 * the panel. Raised when a panel page or asset is asked for, never at boot, so an installation
 * without the build still runs everything but the panel. In this repository `composer panel:build`
 * makes the build.
 */
#[Internal]
final class PanelBuildUnavailable extends RuntimeException
{
    public static function missing(string $manifest): self
    {
        return new self("The panel is not built: {$manifest} does not exist or cannot be read. Build it with `composer panel:build`.");
    }

    public static function malformed(string $manifest, string $reason, ?Throwable $previous = null): self
    {
        return new self("The panel's build manifest {$manifest} is not usable: {$reason} Build the panel again with `composer panel:build`.", 0, $previous);
    }
}
