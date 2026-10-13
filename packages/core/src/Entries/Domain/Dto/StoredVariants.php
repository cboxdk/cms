<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * An entry as a write of several variants reads it (PRD 5.4, 10.1): its id, type, home node and
 * version, and the head of each variant the write asked for that the entry has, by variant key; a
 * variant the entry has no head of is absent from the map.
 */
#[Internal]
final readonly class StoredVariants
{
    /**
     * @param  array<string, StoredHead>  $heads  by variant key
     */
    public function __construct(
        public EntryId $id,
        public TypeId $type,
        public NodeId $home,
        public AggregateVersion $version,
        public array $heads,
    ) {}

    public function head(VariantKey $variant): ?StoredHead
    {
        return $this->heads[$variant->value] ?? null;
    }

    /**
     * The entry with the head of one variant, as a write of that variant alone reads it.
     */
    public function of(VariantKey $variant): StoredEntry
    {
        return new StoredEntry($this->id, $this->type, $this->home, $this->version, $this->head($variant));
    }
}
