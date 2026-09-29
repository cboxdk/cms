<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What SaveNoteAction::resolve() read: the note, or null when it does not exist yet. versions()
 * tells the kernel what to check at commit: the note's shared variant at the version it was read
 * at, or that the note is still absent.
 */
final readonly class NoteAggregates implements Aggregates
{
    public function __construct(
        public EntryId $note,
        public ?StoredNote $stored,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        return $this->stored instanceof StoredNote
            ? new ReadVersions(ReadVersion::at(new VariantRef($this->note, VariantKey::shared()), $this->stored->version))
            : new ReadVersions(ReadVersion::absent($this->note));
    }
}
