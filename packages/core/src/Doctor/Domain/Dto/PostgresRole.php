<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * The attributes of the role the doctor's connection logs in as, and the roles it reaches through
 * membership that give it more power than its own attributes.
 */
#[Internal]
final readonly class PostgresRole
{
    /**
     * @param  list<RoleMembership>  $memberships  the superusers, BYPASSRLS roles and relation owners the role is a member of, sorted by name
     */
    public function __construct(
        public string $name,
        public bool $superuser,
        public bool $bypassRowSecurity,
        public array $memberships,
    ) {}
}
