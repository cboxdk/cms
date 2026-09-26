<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The tables and partitioned tables in the current database with row level security, and those of
 * them that do not force it on their owner (PRD 4.2).
 */
#[Internal]
final readonly class RowSecurity
{
    /**
     * @param  string  $database  the current database
     * @param  int  $enabledCount  how many tables have row level security enabled
     * @param  list<string>  $unforcedTables  schema-qualified tables with row level security enabled but not forced, parents before partitions, at most a few
     * @param  int  $unforcedCount  how many tables have row level security enabled but not forced
     */
    public function __construct(
        public string $database,
        public int $enabledCount,
        public array $unforcedTables,
        public int $unforcedCount,
    ) {}
}
