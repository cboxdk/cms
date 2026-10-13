<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\ReadsContent;
use Cbox\Cms\Contracts\Results\ReadContent;
use LogicException;
use Override;

/**
 * The result of entry.list: a page of entries, newest first, and the id to read the next page
 * after, or null when this page is the last. It carries content (ReadsContent): each entry's
 * fields, the title field of its type, are one entry of contents(), so the query pipeline strips
 * every field above the reader's classification access, writes the read audit where a field's
 * classification requires it, and gives the answer the content keys of every entry listed.
 */
#[Experimental]
final readonly class EntryList implements ReadsContent
{
    /**
     * @param  list<ListedEntry>  $entries
     */
    public function __construct(
        public array $entries,
        public ?EntryId $next,
    ) {}

    #[Override]
    public function contents(): array
    {
        return array_map(static fn (ListedEntry $entry): ReadContent => new ReadContent($entry->id, $entry->home, $entry->type, $entry->fields), $this->entries);
    }

    #[Override]
    public function withContents(array $contents): static
    {
        if (count($contents) !== count($this->entries)) {
            throw new LogicException(sprintf('withContents() got %d entries for the %d entries contents() gave.', count($contents), count($this->entries)));
        }

        $entries = [];

        foreach ($this->entries as $position => $entry) {
            $entries[] = $entry->withFields($contents[$position]->fields);
        }

        return new self($entries, $this->next);
    }
}
