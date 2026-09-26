<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\TestDatabase\Boundary;

use Cbox\Cms\Testkit\Postgres\TestDatabaseName;
use Cbox\Cms\Tooling\TestDatabase\Domain\Checkouts;

/**
 * The checkouts on this host's filesystem: a path that is a directory derives the name of its
 * real path through TestDatabaseName, as the testkit does when it provisions.
 */
final readonly class FilesystemCheckouts implements Checkouts
{
    public function derivedName(string $base, string $checkout): ?string
    {
        clearstatcache();

        return is_dir($checkout) ? TestDatabaseName::for($base, $checkout) : null;
    }
}
