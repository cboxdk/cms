<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Panel\Access\Domain\AccessRoles;

/**
 * The props of access.roles.sections@1, the sections of the roles page (PRD 5.10, 13.4): a slot in
 * the page's sections region, below the page's own list of roles, where an addon adds a section
 * about the roles of the installation, such as how its own permissions are spread over them. It
 * has no props: the roles are the page's own, read as the person, and a section's data query
 * reads what it needs as the viewer.
 */
#[Experimental]
#[PanelPoint(name: AccessRoles::SECTIONS, version: 1, kind: PointKind::Slot, page: AccessRoles::PAGE, since: '1.0', label: 'panel.points.access_roles_sections', region: Region::Sections)]
final readonly class AccessRolesSectionsV1 {}
