<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The registries cms:build writes to bootstrap/cache/cms/ (PRD 13.2), one file each, compiled from
 * the #[Action], #[Command], #[Query], #[Hook] and #[Subscription] attributes and the addon
 * manifests: schema.php holds each addon's schema contributions (PRD 13.3), and rest.php the routes
 * of the REST surface, compiled from the actions exposed on it (GUARDRAILS 2.1).
 *
 * A registry is a case here only when it has an entry type and a source. UI slots (PRD 13.4)
 * become a case with the block that brings them.
 */
#[Experimental]
enum RegistryName: string
{
    case Actions = 'actions';
    case Commands = 'commands';
    case Hooks = 'hooks';
    case Rest = 'rest';
    case Schema = 'schema';
    case Subscribers = 'subscribers';

    public function fileName(): string
    {
        return $this->value.'.php';
    }
}
