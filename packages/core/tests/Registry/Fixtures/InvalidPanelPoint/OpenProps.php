<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\InvalidPanelPoint;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;

/**
 * An action point whose props class is neither final nor readonly.
 */
#[Experimental]
#[PanelPoint(name: 'notes.detail.header', version: 1, kind: PointKind::Action, page: 'notes.detail', since: '1.0', label: 'fixture.points.note_header')]
class OpenProps
{
    public function __construct(public string $title) {}
}
