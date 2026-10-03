<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The registries cms:build writes to bootstrap/cache/cms/ (PRD 13.2), one file each, compiled from
 * the #[Action], #[Command], #[Query], #[Hook], #[Subscription] and #[PanelPoint] attributes and the
 * addon manifests: schema.php holds each addon's schema contributions (PRD 13.3), rest.php the
 * routes of the REST surface, compiled from the actions exposed on it (GUARDRAILS 2.1), and
 * panel.php the panel's extension points by `<name>@<version>` with the contributions to each
 * (PRD 13.4), and addons.php each installed addon's manifest beyond them: its capabilities and
 * its panel API version, accepted experimental points and checked bundle (PRD 13.1, 13.4).
 *
 * A registry is a case here only when it has an entry type and a source.
 */
#[Experimental]
enum RegistryName: string
{
    case Actions = 'actions';
    case Addons = 'addons';
    case Commands = 'commands';
    case Hooks = 'hooks';
    case Panel = 'panel';
    case Rest = 'rest';
    case Schema = 'schema';
    case Subscribers = 'subscribers';

    public function fileName(): string
    {
        return $this->value.'.php';
    }
}
