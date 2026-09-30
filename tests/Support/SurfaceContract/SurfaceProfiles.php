<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use LogicException;

/**
 * The profile of each surface a surface contract test drives (GUARDRAILS 2.1), at most one per
 * surface. all() has one for every case of Surface; SurfaceContractTest holds it to that.
 */
final readonly class SurfaceProfiles
{
    /** @var array<string, SurfaceProfile> by surface */
    private array $profiles;

    public function __construct(SurfaceProfile ...$profiles)
    {
        $bySurface = [];

        foreach ($profiles as $profile) {
            $surface = $profile->surface()->value;
            $bySurface[$surface] = array_key_exists($surface, $bySurface)
                ? throw new LogicException(sprintf('The surface %s has two profiles.', $surface))
                : $profile;
        }

        $this->profiles = $bySurface;
    }

    public static function all(): self
    {
        return new self(new RestProfile, new InertiaProfile, new McpProfile, new CliProfile);
    }

    public function for(Surface $surface): ?SurfaceProfile
    {
        return $this->profiles[$surface->value] ?? null;
    }
}
