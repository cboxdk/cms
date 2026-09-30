<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Override;

/**
 * What entry.create read (PRD 6.2 phase 1): the entry, which the command expects not to exist, its
 * shared variant, and the home node at its version, or null when the node does not exist or the
 * actor's regions do not reach it. The kernel checks at commit that the entry and its variant are
 * still absent and the node still at its version, so two creates of one id commit once.
 */
#[Internal]
final readonly class CreateEntryAggregates implements Aggregates
{
    public function __construct(
        public EntryId $entry,
        public ?StoredEntry $existing,
        public NodeId $home,
        public ?AggregateVersion $homeVersion,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $variant = new VariantRef($this->entry, VariantKey::shared());

        return new ReadVersions(
            new ReadVersion($this->entry, $this->existing?->version),
            new ReadVersion($variant, $this->existing?->head?->version),
            new ReadVersion($this->home, $this->homeVersion),
        );
    }
}
