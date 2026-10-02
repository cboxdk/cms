<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Support\SurfaceContract;

use Cbox\Cms\Contracts\Attributes\Surface;
use LogicException;

/**
 * The profile of each surface that serves reads (GUARDRAILS 2.1), at most one per surface: REST
 * and MCP. Inertia gets its profile with the panel's query pages, and the CLI runs no reads
 * (cms:run runs writes), so a query exposed on either has no profile and fails its test.
 */
final readonly class QuerySurfaceProfiles
{
    /** @var array<string, QuerySurfaceProfile> by surface */
    private array $profiles;

    public function __construct(QuerySurfaceProfile ...$profiles)
    {
        $bySurface = [];

        foreach ($profiles as $profile) {
            $surface = $profile->surface()->value;
            $bySurface[$surface] = array_key_exists($surface, $bySurface)
                ? throw new LogicException(sprintf('The surface %s has two query profiles.', $surface))
                : $profile;
        }

        $this->profiles = $bySurface;
    }

    public static function all(): self
    {
        return new self(new RestQueryProfile, new McpQueryProfile);
    }

    public function for(Surface $surface): ?QuerySurfaceProfile
    {
        return $this->profiles[$surface->value] ?? null;
    }
}
