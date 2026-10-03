<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Contributions\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\CommandRef;

/**
 * What the panel's host holds an addon's code to on a page (PRD 13.4): the digest of the ids of
 * every contribution of the addon that runs code (Registrations), which its registration must
 * match, and the commands its contributions may issue through the host: those of its manifest's
 * issues, or any for the core's own, whose commands the server authorizes as every other.
 */
#[Experimental]
final readonly class AddonRegistration
{
    /**
     * @param  list<CommandRef>  $issues
     */
    public function __construct(
        public AddonNamespace $addon,
        public string $digest,
        public array $issues,
        public bool $anyCommand,
    ) {}
}
