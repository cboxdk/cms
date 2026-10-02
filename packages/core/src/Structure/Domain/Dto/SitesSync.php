<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;

/**
 * The sites to sync (PRD 11.14): the configured sites of `cbox-cms.sites`, in the configured order,
 * each with the locales it publishes in.
 */
#[Internal]
final readonly class SitesSync
{
    /**
     * @param  list<ConfiguredSite>  $sites
     */
    public function __construct(public array $sites) {}
}
