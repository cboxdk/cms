<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicatePanelPoint;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * The next version of the point the other two declare, which is a point of its own.
 */
#[Experimental]
#[PanelPoint(name: 'notes.detail.sections', version: 2, kind: PointKind::Slot, page: 'notes.detail', since: '1.1', label: 'fixture.points.note_sections', region: Region::Sections)]
final readonly class ThirdSections {}
