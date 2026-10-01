<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Core\Placements\Domain\EntryReleaseRef;
use DateTimeImmutable;
use Override;

/**
 * What placement.set_window read (PRD 6.2 phase 1), at the time it read: the placement's version,
 * null when it does not exist; the placement itself, null also when the actor's regions do not
 * reach its node; and every placement of its entry in the locale, for the canonical rule, null with
 * it. For a window that makes the placement live or scheduled it also read the entry's release and
 * its type (invariant 6), and null for both otherwise. The kernel checks at commit that the
 * placement, every other placement of the entry in the locale and, when it was read, the entry's
 * release are still at the versions read.
 */
#[Internal]
final readonly class SetPlacementWindowAggregates implements Aggregates
{
    public function __construct(
        public PlacementId $placement,
        public ?AggregateVersion $version,
        public ?StoredPlacement $stored,
        public Locale $locale,
        public ?LocalePlacements $placements,
        public DateTimeImmutable $at,
        public ?EntryRelease $release,
        public ?TypeDefinition $type,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [new ReadVersion($this->placement, $this->version)];

        if ($this->placements instanceof LocalePlacements) {
            array_push($reads, ...PlacementReads::of($this->placements));
        }

        if ($this->release instanceof EntryRelease) {
            $reads[] = new ReadVersion(new EntryReleaseRef($this->release->entry), $this->release->aggregateVersion());
        }

        return PlacementReads::unique($reads);
    }

    /**
     * The placement's node in the command's locale (PRD 5.10); anywhere when the placement read as
     * absent.
     */
    #[Override]
    public function authorizationScope(): AuthorizationScope
    {
        return $this->stored instanceof StoredPlacement ? AuthorizationScope::on(new AuthorizationTarget($this->stored->node, $this->locale)) : AuthorizationScope::anywhere();
    }
}
