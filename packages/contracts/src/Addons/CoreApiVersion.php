<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A version of the kernel's API for addons: the contracts, attributes, hooks, events and manifest
 * an addon builds on (PRD 13.1, 13.5, 6.3). It is a major and a minor version: a minor version
 * only adds, and a major version may break.
 *
 * An addon's manifest names the version it needs, such as 1.0, which reads as the Composer
 * constraint ^1.0: the kernel's version must have the same major version and at least the minor
 * version. current() is the version of this kernel. cms:build refuses a manifest the current
 * version does not satisfy, as registry_incompatible_core_api.
 */
#[Experimental]
final readonly class CoreApiVersion
{
    /** The major version of this kernel's API. */
    public const int CURRENT_MAJOR = 1;

    /** The minor version of this kernel's API. */
    public const int CURRENT_MINOR = 0;

    /**
     * @throws InvalidAddonManifest when a part is below 0
     */
    public function __construct(
        public int $major,
        public int $minor,
    ) {
        if ($major < 0 || $minor < 0) {
            throw InvalidAddonManifest::because(sprintf('The core API version %d.%d has a part below 0.', $major, $minor));
        }
    }

    public static function current(): self
    {
        return new self(self::CURRENT_MAJOR, self::CURRENT_MINOR);
    }

    /**
     * Whether the kernel's version satisfies this version as a requirement: the same major version
     * and at least the minor version.
     */
    public function satisfiedBy(self $kernel): bool
    {
        return $kernel->major === $this->major && $kernel->minor >= $this->minor;
    }

    /**
     * The version, such as "1.0".
     */
    public function toString(): string
    {
        return $this->major.'.'.$this->minor;
    }

    /**
     * The version as the Composer constraint it means, such as "^1.0".
     */
    public function constraint(): string
    {
        return '^'.$this->toString();
    }
}
