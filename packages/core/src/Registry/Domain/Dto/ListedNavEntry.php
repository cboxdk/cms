<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;
use Cbox\Cms\Contracts\PanelPoints\PageName;

/**
 * A navigation entry the actor may open, as action.list gives it (PRD 13.4): a NavContribution of
 * the compiled registry whose permission the actor holds and whose page the actor gets, with its
 * id, its label, a translation key of its addon's catalogue, its icon, or null, and the id of the
 * page it opens, one of the panel's own pages or an addon's, whose address the panel's pages give.
 */
#[Experimental]
final readonly class ListedNavEntry
{
    public function __construct(
        public ContributionId $id,
        public string $label,
        public ?string $icon,
        public PageName $page,
    ) {}
}
