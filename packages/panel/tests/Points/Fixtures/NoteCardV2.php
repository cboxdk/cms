<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Points\Fixtures;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use DateTimeImmutable;

/**
 * The props of the note card slot, version 2, the newest: one member of each kind of value a
 * point's props hold, so the generated codec, TypeScript, validator and sample of a point are
 * exercised on each.
 */
#[Experimental]
#[PanelPoint(name: 'notes.detail.card', version: 2, kind: PointKind::Slot, page: 'notes.detail', since: '1.1', label: 'fixture.points.note_card', region: Region::Sections)]
final readonly class NoteCardV2
{
    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public NoteAuthor $author,
        public ClassificationAccess $access,
        public ?DateTimeImmutable $editedAt,
        public Locale $locale,
        public ActorId $owner,
        public bool $pinned,
        public int $stars,
        public array $tags,
        public string $title,
        public bool $compact = false,
    ) {}
}
