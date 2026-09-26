<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * lc_messages in a new session of one of the doctor's connections, and where Postgres took it
 * from: pg_settings.source, such as "user" for ALTER ROLE ... SET, "database user" for ALTER ROLE
 * ... IN DATABASE ... SET, "configuration file" or "default".
 */
#[Internal]
final readonly class RoleLcMessages
{
    /**
     * @param  string  $role  the role the connection logs in as
     * @param  string  $connection  the application's connection the doctor copied
     */
    public function __construct(
        public string $role,
        public string $connection,
        public string $value,
        public string $source,
    ) {}
}
