<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Addons;

use Cbox\Cms\Contracts\Attributes\Experimental;
use InvalidArgumentException;

/**
 * An addon manifest names the namespace `app` or `ext`. The application's own fields live under
 * `app`, and `ext` holds every extender's namespace (PRD 11.12), so neither can be an addon's.
 * cms:build reports it as registry_reserved_namespace and writes nothing.
 */
#[Experimental]
final class ReservedAddonNamespace extends InvalidArgumentException
{
    public static function named(string $namespace): self
    {
        return new self(sprintf(
            'The addon namespace "%s" is reserved: "app" is the application\'s namespace and "ext" holds every extender\'s (PRD 11.12). Give the addon a name of its own, such as the last part of its package name.',
            $namespace,
        ));
    }
}
