<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The hooks that run for one version of a command, in the order they run (PRD 6.3, 13.2): phase in
 * pipeline order (authorize, transform, validate), then priority with the lowest first, then
 * package, then class. The list is empty when no hook runs for the version.
 */
#[Experimental]
final readonly class VersionHooks
{
    /**
     * @param  list<HookEntry>  $hooks  in the order they run
     */
    public function __construct(
        public int $version,
        public array $hooks,
    ) {}
}
