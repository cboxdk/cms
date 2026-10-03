<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\PanelHost;

use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\Ownership;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\ReplacementKey;

/**
 * The props of notes.form.any@1, a point of the panel build's host fixture.
 */
#[Stable]
#[PanelPoint(name: 'notes.form.any', version: 1, kind: PointKind::Replacement, page: 'notes.form', since: '1.0', label: 'fixture.points.note_any_value', multiplicity: Multiplicity::Exclusive, ownership: Ownership::Any, keyedBy: ReplacementKey::ValueClass)]
final readonly class NoteAnyValueV1 {}
