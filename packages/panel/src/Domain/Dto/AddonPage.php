<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\PanelPoints\ContributionId;

/**
 * The props of an addon's page (PRD 13.4, section 3.4 of the panel extension architecture), Addon
 * in js/panel: the page's id, the id of the PageContribution the host renders the addon's
 * component of, with its data from the deferred prop of its addon, the addon's namespace, and the
 * address the logout posts to. Its JSON form is addon-page.v1.json in
 * packages/panel/resources/schemas/pages, written only by the generated AddonPageCodecV1
 * (GUARDRAILS 2.2).
 */
#[Internal]
final readonly class AddonPage
{
    public function __construct(
        public ContributionId $page,
        public AddonNamespace $addon,
        public string $logout,
    ) {}
}
