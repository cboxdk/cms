<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * The names a role's permissions may hold (PRD 5.10): the command and query names of the compiled
 * registry, which cms:build registers. A name no command or query has gives nothing, so the role
 * commands refuse it. writes() tells a command, which changes something, from a query, which only
 * reads, so the escalation guard counts only commands as administrative (AdministrativePermissions).
 */
#[Internal]
interface PermissionCatalog
{
    /**
     * Whether a command or a query of the registry has the name, in any version.
     */
    public function has(CommandName $name): bool;

    /**
     * Whether a command of the registry, a write, has the name, in any version: a #[Command] that
     * cms:build registered or the command a write action handles. A query's name is not one.
     */
    public function writes(CommandName $name): bool;
}
