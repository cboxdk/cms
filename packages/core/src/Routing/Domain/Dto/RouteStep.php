<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Routing\Domain\RequestPath;

/**
 * Step 2 of a resolution (PRD 5.9): the longest route of the site in the locale that is a prefix of
 * the path, or null when none is, and what is left of the path after it, which step 3 looks up as
 * a slug; the rest is null without a route.
 */
#[Experimental]
final readonly class RouteStep
{
    /** @var list<string> the prefixes looked up, the longest first */
    public array $candidates;

    public function __construct(
        public RequestPath $path,
        public ?string $route,
        public ?string $rest,
    ) {
        $this->candidates = $path->prefixes();
    }
}
