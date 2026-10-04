<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;

/**
 * What a nav entry shows and opens (contributions.v1.json, `#/$defs/nav`): the translation key of
 * its text and its icon, and the id of the page of its addon it opens, whose address the page's
 * entry in `pages` gives.
 */
#[Internal]
final readonly class NavProp
{
    public function __construct(
        public string $label,
        public ?string $icon,
        public ContributionId $page,
    ) {}
}
