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
use Cbox\Cms\Core\Placements\Domain\PlacementSlugRef;
use Override;

/**
 * What placement.set_slug read (PRD 6.2 phase 1): the placement's version, null when it does not
 * exist; the placement itself, null also when the actor's regions do not reach its node; and the
 * new slug below the placement's node in the locale as an aggregate, which the command expects
 * free, with whether a placement that is not withdrawn has it, null when the placement read as
 * absent. The kernel checks at commit that the placement is still at the version read and the slug
 * still free, so a slug another command claimed meanwhile is version_conflict.
 */
#[Internal]
final readonly class SetPlacementSlugAggregates implements Aggregates
{
    public function __construct(
        public PlacementId $placement,
        public ?AggregateVersion $version,
        public ?StoredPlacement $stored,
        public Locale $locale,
        public ?PlacementSlugRef $slug,
        public bool $taken,
    ) {}

    #[Override]
    public function versions(): ReadVersions
    {
        $reads = [new ReadVersion($this->placement, $this->version)];

        if ($this->slug instanceof PlacementSlugRef) {
            $reads[] = new ReadVersion($this->slug, $this->taken ? AggregateVersion::first() : null);
        }

        return new ReadVersions(...$reads);
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
