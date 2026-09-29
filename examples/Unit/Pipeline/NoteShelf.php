<?php

declare(strict_types=1);

namespace Examples\Unit\Pipeline;

use Cbox\Cms\Contracts\Ids\EntryId;

/**
 * The read port the note actions get in their constructor. An application reads its tables here;
 * the example holds the notes in memory.
 */
final readonly class NoteShelf
{
    /** @var list<StoredNote> */
    private array $notes;

    public function __construct(StoredNote ...$notes)
    {
        $this->notes = array_values($notes);
    }

    public function find(EntryId $note): ?StoredNote
    {
        foreach ($this->notes as $stored) {
            if ($stored->note->equals($note)) {
                return $stored;
            }
        }

        return null;
    }
}
