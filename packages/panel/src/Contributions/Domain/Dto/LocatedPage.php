<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;

/**
 * An addon's page found at an address (PRD 13.4): the id of its PageContribution, which names the
 * view of the page and the contribution whose component the host renders.
 */
#[Experimental]
final readonly class LocatedPage
{
    public function __construct(
        public ContributionId $page,
    ) {}
}
