<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Publishing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementReads;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use DateTimeImmutable;
use Override;

/**
 * What entry.publish read (PRD 6.2 phase 1), at the time it read: the entry with the head of its
 * shared variant and its type, null when the entry does not exist or the actor cannot reach its
 * home; the home placement's version wherever it is, and the placement itself, null also when the
 * actor's regions do not reach its node; and every placement of the entry in every locale, for the
 * canonical rule in the placement's locale and for the dry run's list of what becomes visible; and
 * the revision the command names to release, null when it names none.
 *
 * The kernel checks at commit that the entry, the variant, the placement and every placement of the
 * entry in the placement's locale, with its canonical slot, are still at the versions read.
 */
#[Internal]
final readonly class PublishEntryAggregates implements Aggregates
{
    /**
     * @param  list<LocalePlacements>  $everywhere  one per locale the entry has placements in
     */
    public function __construct(
        public EntryId $entry,
        public ?StoredEntry $stored,
        public ?TypeDefinition $type,
        public PlacementId $placement,
        public ?AggregateVersion $placementVersion,
        public ?StoredPlacement $storedPlacement,
        public Locale $locale,
        public array $everywhere,
        public DateTimeImmutable $at,
        public ?RevisionNumber $revision = null,
    ) {}

    /**
     * Whether the plan releases a revision of the shared variant: the command names one, the type
     * has stages, and it is not the variant's released revision already, which VariantReleasePlanner
     * leaves out of the plan.
     */
    public function releases(): bool
    {
        if (! $this->revision instanceof RevisionNumber || ! $this->type instanceof TypeDefinition || $this->type->capabilities->stages === Stages::None) {
            return false;
        }

        $released = $this->stored?->head instanceof StoredHead ? $this->stored->head->released : null;

        return ! $released instanceof RevisionNumber || ! $released->equals($this->revision);
    }

    /**
     * Every placement of the entry in the command's locale; none when it has none there.
     */
    public function inLocale(): LocalePlacements
    {
        foreach ($this->everywhere as $placements) {
            if ($placements->locale->equals($this->locale)) {
                return $placements;
            }
        }

        return new LocalePlacements($this->entry, $this->locale, []);
    }

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [
            new ReadVersion($this->entry, $this->stored?->version),
            new ReadVersion(new VariantRef($this->entry, VariantKey::shared()), $this->stored?->head?->version),
            new ReadVersion($this->placement, $this->placementVersion),
            ...PlacementReads::of($this->inLocale()),
        ];

        return PlacementReads::unique($reads);
    }

    /**
     * The entry's home and the home placement's node, each in the command's locale: the window is a
     * placement right in that locale (PRD 5.10). When the plan releases a revision, the home in every
     * locale too: the release changes the shared variant, which every locale and every site serves,
     * so it needs what variant.release needs, and a grant limited to some locales, or a deny in one,
     * does not reach it (security review S-1). What read as absent is left out, and anywhere when
     * both did.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        $targets = [];

        if ($this->stored instanceof StoredEntry) {
            $targets[] = new AuthorizationTarget($this->stored->home, $this->locale);

            if ($this->releases()) {
                $targets[] = new AuthorizationTarget($this->stored->home);
            }
        }

        if ($this->storedPlacement instanceof StoredPlacement) {
            $targets[] = new AuthorizationTarget($this->storedPlacement->node, $this->locale);
        }

        return $targets === [] ? AuthorizationScope::anywhere() : AuthorizationScope::on(...$targets);
    }
}
