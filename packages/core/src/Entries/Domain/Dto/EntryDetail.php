<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadsContent;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use LogicException;
use Override;

/**
 * The result of entry.get (PRD 5.4, 5.7): the entry's type, home node, lifecycle and version,
 * every variant head in the order of the variants, the shared variant first, and every placement
 * of the entry the reader reaches in the order of their ids. It ReadsContent: the fields of each
 * head are one entry of contents(), so the query pipeline strips every field above the reader's
 * classification access and those the type does not declare, and writes the read audit the
 * fields require.
 */
#[Experimental]
final readonly class EntryDetail implements ReadsContent
{
    /**
     * @param  list<EntryHead>  $heads
     * @param  list<EntryPlacement>  $placements
     */
    public function __construct(
        public EntryId $id,
        public TypeId $type,
        public NodeId $home,
        public EntryLifecycle $lifecycle,
        public AggregateVersion $version,
        public array $heads,
        public array $placements,
    ) {}

    #[Override]
    public function contents(): array
    {
        return array_map(fn (EntryHead $head): ReadContent => new ReadContent($this->id, $this->home, $this->type, $head->fields), $this->heads);
    }

    #[Override]
    public function withContents(array $contents): static
    {
        if (count($contents) !== count($this->heads)) {
            throw new LogicException(sprintf('withContents() got %d entries for the %d heads contents() gave.', count($contents), count($this->heads)));
        }

        $heads = [];

        foreach ($this->heads as $position => $head) {
            $heads[] = $head->withFields($contents[$position]->fields);
        }

        return new self($this->id, $this->type, $this->home, $this->lifecycle, $this->version, $heads, $this->placements);
    }
}
