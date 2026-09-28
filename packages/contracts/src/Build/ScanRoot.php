<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Build;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * A directory that cms:build scans for classes declared with #[Command] and #[Hook] (PRD 13.2),
 * and the Composer package the classes belong to.
 *
 * The directory is absolute, and every class in it must be autoloadable. The package name orders
 * hooks with the same priority and names the package in the registry and in build errors.
 */
#[Experimental]
final readonly class ScanRoot
{
    /** Composer's own pattern for a package name, anchored with \A and \z. */
    public const string PACKAGE_PATTERN = '/\A[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*\z/';

    public function __construct(
        public string $package,
        public string $directory,
    ) {
        if (preg_match(self::PACKAGE_PATTERN, $package) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Scan root package "%s" is not a Composer package name such as "acme/cms-blog".',
                $package,
            ));
        }

        if (! str_starts_with($directory, '/') && preg_match('/\A[A-Za-z]:[\\\\\/]/', $directory) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Scan root directory "%s" of package "%s" is not an absolute path. Use __DIR__ in the service provider.',
                $directory,
                $package,
            ));
        }
    }
}
