<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;

/**
 * The value of a timeout in a new session of the doctor's connection, and where Postgres took it
 * from (pg_settings.source).
 */
#[Internal]
final readonly class TimeoutSetting
{
    public function __construct(
        public string $role,
        public int $milliseconds,
        public SettingSource $source,
    ) {}

    /** Whether ALTER ROLE ... SET, with or without IN DATABASE, gave the value. */
    public function isSetOnRole(): bool
    {
        return $this->source->isRole();
    }
}
