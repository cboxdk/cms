<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a scan of the scan roots found: the declared commands and hooks, and the problems that make a
 * build fail, in the order they were found.
 */
#[Experimental]
final readonly class Discovery
{
    /**
     * @param  list<CommandEntry>  $commands
     * @param  list<DiscoveredHook>  $hooks
     * @param  list<BuildProblem>  $problems
     */
    public function __construct(
        public array $commands,
        public array $hooks,
        public array $problems,
    ) {}
}
