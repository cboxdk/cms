<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\DevImage\Domain;

/**
 * Whether the node_modules volume of a checkout holds what package-lock.json locks. After
 * `npm ci` in the dev image, tools/bin/dev-image-entry.php writes the SHA-256 of the lock file to
 * FILE in node_modules; a run whose lock file hashes to anything else, or finds no stamp, runs
 * `npm ci` first. `npm ci` removes what node_modules held, the stamp included, so a failed
 * install leaves no stamp and the next run installs again.
 */
final readonly class NodeModulesStamp
{
    public const string FILE = 'node_modules/.cms-package-lock.sha256';

    public static function of(string $lockFile): string
    {
        return hash('sha256', $lockFile)."\n";
    }

    public static function current(string $lockFile, ?string $stamp): bool
    {
        return $stamp === self::of($lockFile);
    }
}
