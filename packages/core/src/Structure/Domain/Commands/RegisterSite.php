<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Commands;

use Cbox\Cms\Contracts\Attributes\Command as CommandName;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\ExpectsVersions;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\SiteHandleRef;
use Override;

/**
 * Registers a site (PRD 5.8, 5.9, 11.14), version 1 of site.register: the caller's id of the new
 * site, its handle, as the configuration names it, the caller's id of its root node, and the
 * locales it publishes in, at least one, each once. In one changeset the root node is created at
 * the top of the tree with the kind site, the site with its locales, and in each locale the route
 * `/` to the root node; site.registered tells about it.
 *
 * Sites and hosts are configuration kept in git and applied at deploy (PRD 11.14), so the command
 * is the kernel's own: cms:sites:sync runs it as the installation operator for each configured site
 * the database lacks, through the maintenance pipeline, and no surface exposes it. It expects the
 * site and its handle absent: a site with the id, or a site with the handle, read or committed
 * meanwhile, is version_conflict. No locale, or a locale named twice, is validation_failed.
 */
#[CommandName('site.register', version: 1)]
#[Experimental]
final readonly class RegisterSite implements ExpectsVersions
{
    /**
     * @param  list<Locale>  $locales
     */
    public function __construct(
        public SiteId $site,
        public SiteHandle $handle,
        public NodeId $root,
        public array $locales,
    ) {}

    /**
     * The site and its handle, absent.
     */
    #[Override]
    public function expectedVersions(): ReadVersions
    {
        return new ReadVersions(ReadVersion::absent($this->site), ReadVersion::absent(new SiteHandleRef($this->handle)));
    }
}
