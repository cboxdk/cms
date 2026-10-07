<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\PanelTypes\Fixtures;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\HostProps;
use Cbox\Cms\Contracts\PanelPoints\Multiplicity;
use Cbox\Cms\Contracts\PanelPoints\Ownership;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\ReplacementKey;

/**
 * The props of a test-only replacement point whose host adds to them in the browser, so its
 * contributions are typed on the SDK's NoteFieldProps, which HostProps names, rather than on
 * these generated props.
 */
#[Experimental]
#[PanelPoint(name: 'notes.form.field', version: 1, kind: PointKind::Replacement, page: 'notes.form', since: '1.0', label: 'fixture.points.note_field', multiplicity: Multiplicity::Exclusive, ownership: Ownership::Own, keyedBy: ReplacementKey::ValueClass)]
#[HostProps('NoteFieldProps')]
final readonly class NoteFieldV1
{
    public function __construct(
        public string $path,
        public ?string $value,
    ) {}
}
