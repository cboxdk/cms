<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\GrantEffect;
use Cbox\Cms\Contracts\Identity\NodePath;
use Cbox\Cms\Contracts\Ids\RoleId;
use Cbox\Cms\Core\Access\Domain\InvalidGrant;

/**
 * One grant of an actor as the access compiler reads it (PRD 5.10): its role with the role's
 * classification ceiling (PRD 12.2), the path of its node, whether it allows or denies, and its
 * locale set, null for every locale. A locale set is not empty and names no locale twice.
 */
#[Internal]
final readonly class Grant
{
    /**
     * @param  list<Locale>|null  $locales
     *
     * @throws InvalidGrant
     */
    public function __construct(
        public RoleId $role,
        public ClassificationAccess $roleCeiling,
        public NodePath $node,
        public GrantEffect $effect,
        public ?array $locales = null,
    ) {
        if ($locales === null) {
            return;
        }

        if ($locales === []) {
            throw InvalidGrant::noLocales($node);
        }

        $seen = [];

        foreach ($locales as $locale) {
            if (isset($seen[$locale->value])) {
                throw InvalidGrant::repeatedLocale($node, $locale);
            }

            $seen[$locale->value] = true;
        }
    }

    /**
     * Whether the grant holds in the locale, or in every locale for null.
     */
    public function holdsIn(?Locale $locale): bool
    {
        if ($this->locales === null) {
            return true;
        }

        return $locale instanceof Locale && array_any($this->locales, static fn (Locale $held): bool => $held->equals($locale));
    }
}
