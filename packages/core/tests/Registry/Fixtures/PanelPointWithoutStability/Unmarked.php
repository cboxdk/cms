<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelPointWithoutStability;

use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;

/**
 * A panel point whose props class says nothing of its stability.
 */
#[PanelPoint(name: 'notes.detail.aside', version: 1, kind: PointKind::Slot, page: 'notes.detail', since: '1.0', label: 'fixture.points.note_aside', region: Region::Aside)]
final readonly class Unmarked {}
