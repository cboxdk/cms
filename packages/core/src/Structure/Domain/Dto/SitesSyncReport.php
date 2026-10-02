<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Results\CatalogError;

/**
 * What cms:sites:sync did (PRD 11.14): one SiteSync per configured site, in the configured order.
 */
#[Internal]
final readonly class SitesSyncReport
{
    /**
     * @param  list<SiteSync>  $sites
     */
    public function __construct(public array $sites) {}

    /**
     * The first error of the first site that did not sync, or null when every site synced.
     */
    public function firstError(): ?CatalogError
    {
        foreach ($this->sites as $site) {
            if ($site->errors !== []) {
                return $site->errors[0];
            }
        }

        return null;
    }
}
