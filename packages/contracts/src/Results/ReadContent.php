<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Results;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;

/**
 * One entry as a read returns it (PRD 6.2, 9.4): the entry, the node the read reached it through
 * (its home node, or the node of the placement it was read at), its type and the fields it read,
 * in the kernel's generic form. The query pipeline strips the fields by the type's classifications
 * and gives the entry's content keys to the answer.
 */
#[Experimental]
final readonly class ReadContent
{
    public function __construct(
        public EntryId $entry,
        public NodeId $node,
        public TypeId $type,
        public FieldValues $fields = new FieldValues,
    ) {}

    /**
     * The same entry with other fields.
     */
    public function withFields(FieldValues $fields): self
    {
        return new self($this->entry, $this->node, $this->type, $fields);
    }

    /**
     * The content keys a cache of this read depends on: `e-{entry}` and `n-{node}` (PRD 9.4).
     *
     * @return list<DependencyKey>
     */
    public function contentKeys(): array
    {
        return [DependencyKey::entry($this->entry), DependencyKey::node($this->node)];
    }
}
