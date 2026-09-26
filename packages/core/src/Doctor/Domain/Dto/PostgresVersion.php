<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The server's version: server_version_num, such as 170011, and server_version, such as "17.11".
 */
#[Internal]
final readonly class PostgresVersion
{
    public function __construct(
        public int $number,
        public string $text,
    ) {}

    public function major(): int
    {
        return intdiv($this->number, 10_000);
    }
}
