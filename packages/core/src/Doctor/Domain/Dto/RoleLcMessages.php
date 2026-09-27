<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * lc_messages that a new session of a role gets, and where Postgres takes it from: the source as
 * pg_settings names it, such as "user" for ALTER ROLE ... SET, "database user" for ALTER ROLE ...
 * IN DATABASE ... SET, "database" for ALTER DATABASE ... SET, "global" for ALTER ROLE ALL ... SET,
 * "configuration file" or "default".
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
        public string $source,
    ) {}
}
