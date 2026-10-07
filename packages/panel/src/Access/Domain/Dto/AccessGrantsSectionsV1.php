<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Panel\Access\Domain\AccessGrants;

/**
 * The props of access.grants.sections@1, the sections of the grants page (PRD 5.10, 13.4): a slot
 * in the page's sections region, below the page's own list of grants, where an addon adds a
 * section about the access of the installation, such as a review of grants about to expire. It
 * has no props: the grants are the page's own, read as the person, and a section's data query
 * reads what it needs as the viewer.
 */
#[Experimental]
#[PanelPoint(name: AccessGrants::SECTIONS, version: 1, kind: PointKind::Slot, page: AccessGrants::PAGE, since: '1.0', label: 'panel.points.access_grants_sections', region: Region::Sections)]
final readonly class AccessGrantsSectionsV1 {}
