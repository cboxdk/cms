<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The Postgres extensions installed in the current database, as pg_extension lists them.
 */
#[Internal]
final readonly class InstalledExtensions
{
    /**
     * @param  string  $database  the current database
     * @param  list<string>  $names  the names of the installed extensions, sorted
     */
    public function __construct(
        public string $database,
        public array $names,
    ) {}
}
