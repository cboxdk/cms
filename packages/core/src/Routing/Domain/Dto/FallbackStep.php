<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Results\FieldPath;
use InvalidArgumentException;

/**
 * The fallback of a resolution (PRD 10.3), for an entry of a type with localization variants: the
 * locales it tried in order, the locale requested first and then those after it in the site's
 * fallback chain (FallbackChain); the first of them with a released row, whose variant the page
 * shows, or null when none has one; whether that is another locale than the one requested; and the
 * fields marked `fallback: field` that the shown variant leaves empty and that took their value
 * from a later locale of the chain, by their address, sorted.
 */
#[Experimental]
final readonly class FallbackStep
{
    /**
     * @param  list<Locale>  $tried  the locale requested first
     * @param  list<FieldPath>  $fields  sorted by their address
     *
     * @throws InvalidArgumentException when nothing was tried, the resolved locale was not tried, fallback does not say whether it is the first locale tried, or fields fell back without a resolved locale
     */
    public function __construct(
        public array $tried,
        public ?Locale $resolved,
        public bool $fallback,
        public array $fields,
    ) {
        $first = $tried[0] ?? throw new InvalidArgumentException('A fallback tries the locale requested first.');

        if ($resolved instanceof Locale && ! array_any($tried, static fn (Locale $locale): bool => $locale->equals($resolved))) {
            throw new InvalidArgumentException(sprintf('A fallback resolves to a locale it tried; %s is not one.', $resolved->value));
        }

        if ($fallback !== ($resolved instanceof Locale && ! $resolved->equals($first))) {
            throw new InvalidArgumentException('A fallback falls back exactly when it resolves to another locale than the one requested.');
        }

        if ($fields !== [] && ! $resolved instanceof Locale) {
            throw new InvalidArgumentException('A field falls back only below a locale the resolution shows.');
        }
    }
}
