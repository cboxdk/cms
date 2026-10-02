<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Structure\Domain\SiteHandleRef;
use Override;

/**
 * What site.register read (PRD 6.2 phase 1): the site with the command's id, or null, and the site
 * that has the command's handle, or null. The kernel checks at commit that both are still absent,
 * so a registration of the same id or handle committed meanwhile is version_conflict.
 */
#[Internal]
final readonly class RegisterSiteAggregates implements Aggregates
{
    public function __construct(
        public SiteId $site,
        public ?StoredSite $current,
        public SiteHandleRef $handle,
        public ?StoredSite $holder,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return new ReadVersions(
            new ReadVersion($this->site, $this->current?->version),
            new ReadVersion($this->handle, $this->holder instanceof StoredSite ? AggregateVersion::first() : null),
        );
    }

    /**
     * Anywhere: a site is not below a node of the tree, it is a root of it.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return AuthorizationScope::anywhere();
    }
}
