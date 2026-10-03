<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * The props of notes.detail.sections@1, a point of the panel build's host fixture.
 */
#[Experimental]
#[PanelPoint(name: 'notes.detail.sections', version: 1, kind: PointKind::Slot, page: 'notes.detail', since: '1.0', label: 'fixture.points.note_sections', region: Region::Sections)]
final readonly class NoteSectionsV1
{
    public function __construct(public string $note, public int $count) {}
}
