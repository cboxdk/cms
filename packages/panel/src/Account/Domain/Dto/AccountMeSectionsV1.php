<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Account\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Panel\Account\Domain\AccountMe;

/**
 * The props of account.me.sections@1, the sections of the who-am-I page (PRD 13.4): a slot in the
 * page's sections region, below the page's own profile and grants, where an addon adds a section
 * about the viewer, such as the sessions it has open. Its props are the viewer's actor id, which
 * a section's data query takes as its input; the profile and the grants are the page's own and
 * never handed on.
 */
#[Experimental]
#[PanelPoint(name: AccountMe::SECTIONS, version: 1, kind: PointKind::Slot, page: AccountMe::PAGE, since: '1.0', label: 'panel.points.account_me_sections', region: Region::Sections)]
final readonly class AccountMeSectionsV1
{
    public function __construct(
        public ActorId $actor,
    ) {}
}
