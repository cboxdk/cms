<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Stable;

/**
 * A panel point's stability, read from the stability attribute on its props class (GUARDRAILS
 * 2.3): a stable point follows semver of the panel API, an experimental one may change in a minor
 * release and needs the addon's consent, and an internal one is the core's own wiring and never
 * contributable.
 */
#[Experimental]
enum PointStability: string
{
    case Stable = 'stable';
    case Experimental = 'experimental';
    case Internal = 'internal';

    /**
     * The attribute class that declares it.
     *
     * @return class-string
     */
    public function attribute(): string
    {
        return match ($this) {
            self::Stable => Stable::class,
            self::Experimental => Experimental::class,
            self::Internal => Internal::class,
        };
    }
}
