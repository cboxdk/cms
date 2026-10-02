<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Access\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\CommandName;

/**
 * The text[] literal of a role's permissions, as the role writers hand them to their functions. A
 * command name holds only lowercase letters, digits, underscores and dots, none of which the
 * literal quotes.
 */
#[Internal]
final readonly class RoleRows
{
    /**
     * @param  list<CommandName>  $permissions
     */
    public static function permissions(array $permissions): string
    {
        return '{'.implode(',', array_map(static fn (CommandName $permission): string => $permission->value, $permissions)).'}';
    }
}
