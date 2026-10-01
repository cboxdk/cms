<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Publishing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementReads;
use DateTimeImmutable;
use Override;

/**
 * What entry.unpublish read (PRD 6.2 phase 1), at the time it read: the entry with the head of its
 * shared variant, null when the entry does not exist or the actor cannot reach its home, and every
 * placement of the entry in every locale, on every site. The kernel checks at commit that the
 * entry, the variant and every placement of the entry, with the canonical slot of each locale, are
 * still at the versions read, so a window opened meanwhile is version_conflict, not a placement
 * left live.
 */
#[Internal]
final readonly class UnpublishEntryAggregates implements Aggregates
{
    /**
     * @param  list<LocalePlacements>  $everywhere  one per locale the entry has placements in
     */
    public function __construct(
        public EntryId $entry,
        public ?StoredEntry $stored,
        public array $everywhere,
        public DateTimeImmutable $at,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [
            new ReadVersion($this->entry, $this->stored?->version),
            new ReadVersion(new VariantRef($this->entry, VariantKey::shared()), $this->stored?->head?->version),
        ];

        foreach ($this->everywhere as $placements) {
            array_push($reads, ...PlacementReads::of($placements));
        }

        return PlacementReads::unique($reads);
    }

    /**
     * The entry's home in every locale: taking content back everywhere is a content right (PRD
     * 5.10); anywhere when the entry read as absent.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->stored instanceof StoredEntry ? AuthorizationScope::on(new AuthorizationTarget($this->stored->home)) : AuthorizationScope::anywhere();
    }
}
