<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\DuplicatePanelPoint;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\DowncastsFromNewest;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * One of two props classes that declare the same panel point and version.
 * It downcasts from the next version, so only the duplicate is refused.
 *
 * @implements DowncastsFromNewest<ThirdSections>
 */
#[Experimental]
#[PanelPoint(name: 'notes.detail.sections', version: 1, kind: PointKind::Slot, page: 'notes.detail', since: '1.0', label: 'fixture.points.note_sections', region: Region::Sections)]
final readonly class FirstSections implements DowncastsFromNewest
{
    public static function downcast(object $newest): static
    {
        return new self;
    }
}
