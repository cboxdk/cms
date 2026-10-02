<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\SiteSyncOutcome;

/**
 * The sync of one configured site (PRD 11.14): its handle, what happened, the site and its root
 * node when the site is registered (null for a rejection), the changeset that registered it now,
 * the locales configured and the locales registered (empty for a rejection), and the errors of a
 * drift or a rejection, each with its catalog code.
 */
#[Internal]
final readonly class SiteSync
{
    /**
     * @param  list<Locale>  $configured
     * @param  list<Locale>  $registered
     * @param  list<CatalogError>  $errors
     */
    public function __construct(
        public SiteHandle $handle,
        public SiteSyncOutcome $outcome,
        public ?SiteId $site,
        public ?NodeId $root,
        public ?ChangesetId $changeset,
        public array $configured,
        public array $registered,
        public array $errors = [],
    ) {}
}
