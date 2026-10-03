<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Registry\Fixtures\Panel;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\Ownership;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\ReplacementKey;

/**
 * A replacement of the note form's field inputs, keyed by field type, for keys the addon owns.
 */
#[Experimental]
#[PanelPoint(name: 'notes.form.field', version: 1, kind: PointKind::Replacement, page: 'notes.form', since: '1.0', label: 'fixture.points.note_field', multiplicity: Multiplicity::Exclusive, ownership: Ownership::Own, keyedBy: ReplacementKey::FieldType)]
final readonly class NoteFieldInputV1
{
    public function __construct(public string $path) {}
}
