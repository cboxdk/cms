<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a scan of the scan roots found: the declared commands, hooks, queries, actions,
 * subscribers and panel points, and the problems that make a build fail, in the order they were found.
 */
#[Experimental]
final readonly class Discovery
{
    /**
     * @param  list<CommandEntry>  $commands
     * @param  list<DiscoveredHook>  $hooks
     * @param  list<BuildProblem>  $problems
     * @param  list<QueryEntry>  $queries
     * @param  list<DiscoveredAction>  $actions
     * @param  list<SubscriberEntry>  $subscribers
     * @param  list<PanelPointEntry>  $panelPoints
     */
    public function __construct(
        public array $commands,
        public array $hooks,
        public array $problems,
        public array $queries = [],
        public array $actions = [],
        public array $subscribers = [],
        public array $panelPoints = [],
    ) {}
}
