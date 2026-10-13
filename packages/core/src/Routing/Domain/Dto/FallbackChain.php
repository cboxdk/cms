<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Core\Routing\Domain\InvalidRoutingValue;

/**
 * A site's fallback chain (PRD 10.3): the locales, in order, that a page in one of them falls back
 * along, such as de-AT, de, en. A read in a locale of the chain tries that locale first and then
 * every locale after it, so de-AT falls back to de and then to en, de to en, and en to nothing; a
 * read in a locale outside the chain tries that locale alone. Each locale is in the chain once.
 * An empty chain, the default, falls back to nothing.
 */
#[Experimental]
final readonly class FallbackChain
{
    /**
     * @param  list<Locale>  $locales  in the order a read falls back along
     *
     * @throws InvalidRoutingValue for a locale named twice
     */
    public function __construct(
        public array $locales = [],
    ) {
        $seen = [];

        foreach ($locales as $locale) {
            if (isset($seen[$locale->value])) {
                throw InvalidRoutingValue::repeatedFallbackLocale($locale);
            }

            $seen[$locale->value] = true;
        }
    }

    /**
     * The locales a read in the locale tries, in order: the locale, then each locale after it in
     * the chain, or the locale alone when the chain does not name it.
     *
     * @return non-empty-list<Locale>
     */
    public function from(Locale $locale): array
    {
        foreach ($this->locales as $index => $chained) {
            if ($chained->equals($locale)) {
                return [$locale, ...array_slice($this->locales, $index + 1)];
            }
        }

        return [$locale];
    }
}
