<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Addons\InvalidAddonManifest;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * A version of the panel's API for addon UI (PRD 13.4): the stable panel points and their props,
 * the host API, the stable tokens, the kit's props, the shared modules and the React major. It is
 * separate from the Composer version and from CoreApiVersion. A minor version only adds, and a
 * major version may break.
 *
 * An addon's PanelContributions name the version they need, such as 1.0, which reads as the
 * Composer constraint ^1.0: the panel's version must have the same major version and at least the
 * minor version. current() is the version of this panel. cms:build refuses contributions the
 * current version does not satisfy, as registry_incompatible_panel_api.
 */
#[Experimental]
final readonly class PanelApiVersion
{
    /** The major version of this panel's API. */
    public const int CURRENT_MAJOR = 1;

    /** The minor version of this panel's API. */
    public const int CURRENT_MINOR = 0;

    /**
     * @throws InvalidAddonManifest when a part is below 0
     */
    public function __construct(
        public int $major,
        public int $minor,
    ) {
        if ($major < 0 || $minor < 0) {
            throw InvalidAddonManifest::because(sprintf('The panel API version %d.%d has a part below 0.', $major, $minor));
        }
    }

    public static function current(): self
    {
        return new self(self::CURRENT_MAJOR, self::CURRENT_MINOR);
    }

    /**
     * Whether the panel's version satisfies this version as a requirement: the same major version
     * and at least the minor version.
     */
    public function satisfiedBy(self $panel): bool
    {
        return $panel->major === $this->major && $panel->minor >= $this->minor;
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
