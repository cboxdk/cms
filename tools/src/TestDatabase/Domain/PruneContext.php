<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\TestDatabase\Domain;

use InvalidArgumentException;

/**
 * Where `composer test-db:prune` runs: the configured test database ($base, such as cms_test),
 * the test database of the checkout that runs it, and the name of this host as the testkit
 * records it (gethostname()).
 */
final readonly class PruneContext
{
    public function __construct(
        public string $base,
        public string $current,
        public string $host,
    ) {
        if ($base === '' || $host === '') {
            throw new InvalidArgumentException('Pruning test databases needs the configured database and the host name.');
        }

        if (! PrunePlan::isTestDatabaseName($base, $current)) {
            throw new InvalidArgumentException("The test database of this checkout, {$current}, is not named {$base}_<12 hex digits>.");
        }
    }
}
