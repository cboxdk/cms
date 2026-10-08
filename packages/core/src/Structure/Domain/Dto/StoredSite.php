<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Structure\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;

/**
 * A site as the database holds it (PRD 5.9): its id, handle, root node, version, and the locales it
 * publishes in, sorted by their tag.
 */
#[Internal]
final readonly class StoredSite
{
    /**
     * @param  list<Locale>  $locales  sorted by their tag
     */
    public function __construct(
        public SiteId $id,
        public SiteHandle $handle,
        public NodeId $root,
        public AggregateVersion $version,
        public array $locales,
    ) {}

    /**
     * Whether the site publishes in the locale.
     */
    public function publishes(Locale $locale): bool
    {
        return array_any($this->locales, static fn (Locale $held): bool => $held->equals($locale));
    }

    /**
     * Whether the site publishes in exactly these locales, in any order.
     *
     * @param  list<Locale>  $locales
     */
    public function publishesIn(array $locales): bool
    {
        return self::tags($locales) === self::tags($this->locales);
    }

    /**
     * The tags of the locales, sorted and each once.
     *
     * @param  list<Locale>  $locales
     * @return list<string>
     */
    public static function tags(array $locales): array
    {
        $tags = array_values(array_unique(array_map(static fn (Locale $locale): string => $locale->value, $locales)));
        sort($tags, SORT_STRING);

        return $tags;
    }
}
