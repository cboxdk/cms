<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Valid;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * A panel point of the fixture: the aside of the note page, with the note's title as its props.
 */
#[Experimental]
#[PanelPoint(name: 'fixture.note.aside', version: 1, kind: PointKind::Slot, page: 'fixture.note', since: '1.0', label: 'fixture.points.note_aside', region: Region::Aside)]
final readonly class NoteCardV1
{
    public function __construct(public string $title) {}
}
