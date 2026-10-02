<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Dto\StoredSite;

/**
 * Reads the registered sites one at a time, by id or by handle (PRD 5.9, 11.14), with their locales.
 * Which sites exist and in which locales they publish is what the public resolves a URL against, so
 * a read needs no actor context: it runs inside a command transaction, where site.register reads the
 * site it registers, and outside one, where cms:sites:sync compares the configuration with the
 * database. It never lists the sites.
 */
#[Internal]
interface SiteDirectory
{
    /**
     * The site with the id, or null.
     */
    public function find(SiteId $site): ?StoredSite;

    /**
     * The site with the handle, or null.
     */
    public function named(SiteHandle $handle): ?StoredSite;
}
