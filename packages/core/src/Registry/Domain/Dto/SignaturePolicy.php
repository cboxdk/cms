<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\Registry\Domain\PublisherKey;

/**
 * How cms:build holds the addons' panel bundles to their signatures (PRD 13.8, decision D8 of
 * the panel extension architecture): the publisher keys the installation trusts, by the addon's
 * Composer package, from cbox-cms.addons.publishers, and whether a bundle of an addon the
 * installation trusts no key for passes unsigned, which only the local environment allows.
 */
#[Experimental]
final readonly class SignaturePolicy
{
    /**
     * @param  array<string, list<PublisherKey>>  $publishers  by package
     */
    public function __construct(
        public array $publishers = [],
        public bool $unsignedAllowed = false,
    ) {}

    /**
     * The keys the installation trusts for the addon of the package.
     *
     * @return list<PublisherKey>
     */
    public function keysOf(string $package): array
    {
        return $this->publishers[$package] ?? [];
    }
}
