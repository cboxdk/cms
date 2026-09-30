<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Routing\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The handle of a site, as the sites table holds it (PRD 5.9): a lower-case letter followed by at
 * most 62 lower-case letters, digits or underscores. The configuration names a site by it.
 */
#[Experimental]
final readonly class SiteHandle
{
    private const string PATTERN = '/\A[a-z][a-z0-9_]{0,62}\z/';

    /**
     * @throws InvalidRoutingValue for anything else
     */
    public function __construct(public string $value)
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw InvalidRoutingValue::siteHandle($value);
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
