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
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredVariants;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementReads;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use DateTimeImmutable;
use Override;

/**
 * What entry.publish version 2 read (PRD 6.2 phase 1), at the time it read: the entry with the
 * heads of the variants the command names and its type, null when the entry does not exist or the
 * actor cannot reach its home; the home placement's version wherever it is, and the placement
 * itself, null also when the actor's regions do not reach its node; and every placement of the
 * entry in every locale, for the canonical rule in the placement's locale and for the dry run's
 * list of what becomes visible.
 *
 * The kernel checks at commit that the entry, each variant, the placement and every placement of
 * the entry in the placement's locale, with its canonical slot, are still at the versions read.
 */
#[Internal]
final readonly class PublishLocalizedEntryAggregates implements Aggregates
{
    /**
     * @param  non-empty-list<VariantRelease>  $variants  the variants the command names, in its order
     * @param  list<LocalePlacements>  $everywhere  one per locale the entry has placements in
     */
    public function __construct(
        public EntryId $entry,
        public array $variants,
        public ?StoredVariants $stored,
        public ?TypeDefinition $type,
        public PlacementId $placement,
        public ?AggregateVersion $placementVersion,
        public ?StoredPlacement $storedPlacement,
        public Locale $locale,
        public array $everywhere,
        public DateTimeImmutable $at,
    ) {}

    /**
     * Whether the plan releases a revision of the variant: the command names one, the type has
     * stages, and it is not the variant's released revision already, which VariantReleasePlanner
     * leaves out of the plan.
     */
    public function releases(VariantRelease $variant): bool
    {
        if (! $variant->revision instanceof RevisionNumber || ! $this->type instanceof TypeDefinition || $this->type->capabilities->stages === Stages::None) {
            return false;
        }

        $head = $this->stored?->head($variant->variant);
        $released = $head instanceof StoredHead ? $head->released : null;

        return ! $released instanceof RevisionNumber || ! $released->equals($variant->revision);
    }

    /**
     * Whether the shared variant has a released revision before the plan, or is public as soon as
     * it is saved, as for a type with stages none (invariant 6).
     */
    public function sharedReleased(): bool
    {
        return $this->type?->capabilities->stages === Stages::None
            || $this->stored?->head(VariantKey::shared())?->released instanceof RevisionNumber;
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
            ...array_map(fn (VariantRelease $variant): ReadVersion => new ReadVersion(new VariantRef($this->entry, $variant->variant), $this->stored?->head($variant->variant)?->version), $this->variants),
            new ReadVersion($this->placement, $this->placementVersion),
            ...PlacementReads::of($this->inLocale()),
        ];

        return PlacementReads::unique($reads);
    }

    /**
     * The entry's home and the home placement's node, each in the command's locale: the window is a
     * placement right in that locale (PRD 5.10). For each variant the plan releases, the home in its
     * locale, and in every locale for the shared variant, which every locale and every site serves,
     * so it needs what variant.release needs (security review S-1). What read as absent is left
     * out, and anywhere when both did.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        $targets = [];

        if ($this->stored instanceof StoredVariants) {
            $targets[] = new AuthorizationTarget($this->stored->home, $this->locale);

            foreach ($this->variants as $variant) {
                if ($this->releases($variant)) {
                    $targets[] = new AuthorizationTarget($this->stored->home, $variant->variant->locale);
                }
            }
        }

        if ($this->storedPlacement instanceof StoredPlacement) {
            $targets[] = new AuthorizationTarget($this->storedPlacement->node, $this->locale);
        }

        return $targets === [] ? AuthorizationScope::anywhere() : AuthorizationScope::on(...$targets);
    }
}
