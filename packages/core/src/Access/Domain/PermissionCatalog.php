<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * The names a role's permissions may hold (PRD 5.10): the command and query names of the compiled
 * registry, which cms:build registers. A name no command or query has gives nothing, so the role
 * commands refuse it.
 */
#[Internal]
interface PermissionCatalog
{
    /**
     * Whether a command or a query of the registry has the name, in any version.
     */
    public function has(CommandName $name): bool;
}
