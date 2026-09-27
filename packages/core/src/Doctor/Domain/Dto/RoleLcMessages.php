<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Doctor\Domain\SettingSource;

/**
 * lc_messages that a new session of a role gets, and where Postgres takes it from, as pg_settings
 * names the source.
 */
#[Internal]
final readonly class RoleLcMessages
{
    /**
     * @param  string  $role  the role whose value it is
     * @param  string  $connection  the application's connection the doctor read it on
     */
    public function __construct(
        public string $role,
        public string $connection,
        public string $value,
        public SettingSource $source,
    ) {}
}
