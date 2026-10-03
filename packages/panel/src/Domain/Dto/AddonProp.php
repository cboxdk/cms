<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Domain\Dto;

use Cbox\Cms\Contracts\Addons\AddonNamespace;
use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * An addon with an active contribution that runs code on a page (contributions.v1.json,
 * `#/$defs/addon`): its namespace, the digest of the ids its code must register, and the commands
 * its contributions may issue through the host, `<name>@<version>`, or any command for the core's
 * own.
 */
#[Internal]
final readonly class AddonProp
{
    /**
     * @param  list<string>  $issues
     */
    public function __construct(
        public AddonNamespace $addon,
        public string $registration,
        public array $issues,
        public bool $anyCommand,
    ) {}
}
