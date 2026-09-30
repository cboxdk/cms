<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;

/**
 * A site as a placement command reads it (PRD 5.9): its version, the path of its root node and the
 * locales it publishes in.
 */
#[Internal]
final readonly class StoredSite
{
    /**
     * @param  list<Locale>  $locales
     */
    public function __construct(
        public SiteId $id,
        public AggregateVersion $version,
        public NodePath $root,
        public array $locales,
    ) {}

    /**
     * Whether the node at the path is the site's root or below it.
     */
    public function holds(NodePath $node): bool
    {
        return $this->root->contains($node);
    }

    public function publishes(Locale $locale): bool
    {
        return array_any($this->locales, fn (Locale $published): bool => $published->equals($locale));
    }
}
