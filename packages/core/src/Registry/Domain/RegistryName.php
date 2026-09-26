<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The six registries cms:build writes to bootstrap/cache/cms/ (PRD 13.2), one file each.
 *
 * Actions, commands and hooks come from the attributes. Subscribers, slots and schema
 * contributions have no source yet: their files hold empty lists until the blocks that bring them
 * (PRD 7, 13.3 and 13.4) add entry types and raise the format.
 */
#[Experimental]
enum RegistryName: string
{
    case Actions = 'actions';
    case Commands = 'commands';
    case Hooks = 'hooks';
    case Subscribers = 'subscribers';
    case Slots = 'slots';
    case Schema = 'schema';

    public function fileName(): string
    {
        return $this->value.'.php';
    }
}
