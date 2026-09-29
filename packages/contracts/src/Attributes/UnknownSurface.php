<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Attributes;

use InvalidArgumentException;

/**
 * #[Action] lists a surface that is not a case of Surface, such as the string 'rest' instead of
 * Surface::Rest. cms:build reports it as registry_unknown_surface.
 */
#[Experimental]
final class UnknownSurface extends InvalidArgumentException
{
    public static function listed(string $surface): self
    {
        return new self(sprintf(
            '#[Action] lists "%s", which is not a surface. List cases of %s: %s.',
            $surface,
            Surface::class,
            implode(', ', array_map(static fn (Surface $case): string => 'Surface::'.$case->name, Surface::cases())),
        ));
    }
}
