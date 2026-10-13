<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementReads;
use Override;

/**
 * What variant.delete read (PRD 6.2 phase 1): the entry with the head of the variant the command
 * deletes, or null when the entry does not exist or the actor's regions do not reach its home, its
 * type, and every placement of the entry in the variant's locale, on every site, or null for the
 * shared variant, which has no locale. The kernel checks at commit that the entry, the variant,
 * every one of those placements and the entry's canonical slot in the locale are still at the
 * versions read, so a placement opened meanwhile is version_conflict, not a placement left live.
 */
#[Internal]
final readonly class DeleteVariantAggregates implements Aggregates
{
    public function __construct(
        public VariantRef $variant,
        public ?StoredEntry $stored,
        public ?TypeDefinition $type = null,
        public ?LocalePlacements $placements = null,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [
            new ReadVersion($this->variant->entry, $this->stored?->version),
            new ReadVersion($this->variant, $this->stored?->head?->version),
        ];

        if ($this->placements instanceof LocalePlacements) {
            array_push($reads, ...PlacementReads::of($this->placements));
        }

        return PlacementReads::unique($reads);
    }

    /**
     * The entry's home in the variant's locale (PRD 5.10, 10.3), every locale for the shared
     * variant, which the action refuses; anywhere when the entry read as absent.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->stored instanceof StoredEntry
            ? AuthorizationScope::on(new AuthorizationTarget($this->stored->home, $this->variant->variant->locale))
            : AuthorizationScope::anywhere();
    }
}
