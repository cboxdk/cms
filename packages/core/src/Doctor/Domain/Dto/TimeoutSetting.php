<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The value of a timeout in a new session of the doctor's connection, and where Postgres took it
 * from: pg_settings.source, such as "user" for ALTER ROLE ... SET, "database user" for ALTER ROLE
 * ... IN DATABASE ... SET, "client" for a connection option, or "default".
 */
#[Internal]
final readonly class TimeoutSetting
{
    public function __construct(
        public string $role,
        public int $milliseconds,
        public string $source,
    ) {}

    public function isSetOnRole(): bool
    {
        return $this->source === 'user' || $this->source === 'database user';
    }
}
