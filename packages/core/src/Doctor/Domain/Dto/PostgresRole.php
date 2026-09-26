<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The attributes of the role the doctor's connection logs in as.
 */
#[Internal]
final readonly class PostgresRole
{
    public function __construct(
        public string $name,
        public bool $superuser,
        public bool $bypassRowSecurity,
    ) {}
}
