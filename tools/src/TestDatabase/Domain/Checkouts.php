<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\TestDatabase\Domain;

/**
 * What a checkout path on this host means now, for `composer test-db:prune`.
 */
interface Checkouts
{
    /**
     * The name of the test database the checkout at $checkout derives from the configured
     * database $base today (TestDatabaseName::for), or null when $checkout is no longer a
     * directory.
     */
    public function derivedName(string $base, string $checkout): ?string;
}
