<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Doctor\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A role the doctor's role is a member of, directly or through other roles, that gives more power
 * than the app role may have (PRD 4.2): a superuser, a role with BYPASSRLS, or the owner of
 * relations, who can alter or drop them and turn their row level security off.
 */
#[Internal]
final readonly class RoleMembership
{
    public function __construct(
        public string $name,
        public bool $superuser,
        public bool $bypassRowSecurity,
        public bool $ownsRelations,
    ) {}
}
