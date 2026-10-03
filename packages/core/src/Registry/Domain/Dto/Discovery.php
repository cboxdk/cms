<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What a scan of the scan roots found: the declared commands, hooks, queries, actions,
 * subscribers and panel points, and the problems that make a build fail, in the order they were found.
 * packages maps every class a scan root declares, by its lower-case name, to the package of the
 * root, so the build can tell which addon owns a class.
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
     * @param  array<string, string>  $packages  package by lower-case class name
     */
    public function __construct(
        public array $commands,
        public array $hooks,
        public array $problems,
        public array $queries = [],
        public array $actions = [],
        public array $subscribers = [],
        public array $panelPoints = [],
        public array $packages = [],
    ) {}

    /**
     * The package of the scan root that declares the class, or null when no scan root does. PHP
     * class names are compared without case, as PHP compares them.
     */
    public function packageOf(string $class): ?string
    {
        return $this->packages[strtolower(ltrim($class, '\\'))] ?? null;
    }
}
