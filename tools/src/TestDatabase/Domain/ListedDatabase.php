<?php

declare(strict_types=1);

namespace Cbox\Cms\Tooling\TestDatabase\Domain;

/**
 * A database on the test server as the owner role sees it in pg_database: its name and its
 * COMMENT ON DATABASE, or null when it has none.
 */
final readonly class ListedDatabase
{
    public function __construct(
        public string $name,
        public ?string $comment,
    ) {}
}
