<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;

/**
 * The reads of the entry commands' resolve phase (PRD 6.2 phase 1): an entry with the head of one
 * of its variants, and a node's version. It runs in the command transaction under the call's actor
 * context, so a row the actor's regions do not reach reads as absent (PRD 5.10). Each read is one
 * lookup by key, whatever else the node holds (GUARDRAILS 4.1). It takes no lock; the commit locks
 * what was read and checks its version.
 */
#[Internal]
interface EntryReader
{
    /**
     * The entry with the head of the variant, or null when the entry is absent.
     */
    public function entry(EntryId $entry, VariantKey $variant): ?StoredEntry;

    /**
     * The node's version, or null when the node is absent.
     */
    public function node(NodeId $node): ?AggregateVersion;
}
