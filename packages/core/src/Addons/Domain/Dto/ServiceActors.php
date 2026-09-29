<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Addons\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * The service actor of each installed addon (PRD 13.1, 5.16, invariant 21), by the addon's
 * namespace, from `cbox-cms.addons.service_actors`. An addon's subscribers run as its service
 * actor, with that actor's own grants, never as the system. The actor is created when the
 * installation approves the addon's capabilities, so its id belongs to the installation and not
 * to the addon's package.
 */
#[Internal]
final readonly class ServiceActors
{
    /**
     * @param  array<string, ActorId>  $actors  by addon namespace
     */
    public function __construct(private array $actors = []) {}

    /**
     * The service actor of the addon, or null when none is configured.
     */
    public function of(AddonNamespace $addon): ?ActorId
    {
        return $this->actors[$addon->value] ?? null;
    }
}
