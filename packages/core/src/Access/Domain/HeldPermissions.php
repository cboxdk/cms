<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Identity\Principal;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Core\Access\Domain\Dto\PermissionsHeld;

/**
 * Which of a list of commands and reads a verified principal may run somewhere, with its
 * AccessContext (PRD 5.10, 13.4): what the panel asks once per page, to show a viewer only the
 * contributions whose `requires` the viewer holds, and to encode their props at the viewer's
 * classification access. A name is held as the query authorizer decides a read that needs a
 * permission: through a role whose permissions name it and that reaches some node in some locale,
 * as the PermissionRule decides, and for an actor that acts on behalf of others only when every
 * actor of its chain holds it too (PRD 5.16). The anonymous principal holds none.
 *
 * It reads in a transaction of its own, which it ends before it returns, as AccessContexts does.
 */
#[Internal]
interface HeldPermissions
{
    /**
     * @param  list<CommandName>  $permissions  the names to decide, each once or more
     */
    public function of(Principal $principal, array $permissions): PermissionsHeld;
}
