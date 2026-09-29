<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Attributes\Command as CommandType;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\Command;

/**
 * Saves a note's title: creates the note, placed below its home node, when it does not exist yet,
 * and otherwise writes a new revision. Version 1 of note.save.
 */
#[CommandType('note.save', version: 1)]
final readonly class SaveNote implements Command
{
    public function __construct(
        public EntryId $note,
        public TypeId $type,
        public NodeId $home,
        public SiteId $site,
        public string $title,
    ) {}
}
