<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Queries;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Query as QueryName;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\RequestPath;

/**
 * Resolves a URL to the placement it shows (PRD 5.9), version 1 of path.resolve: the host the
 * request names, which the configuration maps to a site, the locale, and the path.
 */
#[QueryName('path.resolve', version: 1)]
#[Experimental]
final readonly class ResolvePath implements Query
{
    public function __construct(
        public Host $host,
        public Locale $locale,
        public RequestPath $path,
    ) {}
}
