<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelPointWithoutDowncast;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * Version 1 of the aside of the note form, an older version without a downcast.
 */
#[Experimental]
#[PanelPoint(name: 'notes.form.aside', version: 1, kind: PointKind::Slot, page: 'notes.form', since: '1.0', label: 'fixture.points.note_aside', region: Region::Aside)]
final readonly class AsideV1
{
    public function __construct(public string $command) {}
}
