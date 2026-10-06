<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Pipeline\Result;

/**
 * The result of action.list (GUARDRAILS 8, PRD 13.2, 13.4): the actions of the compiled registry
 * exposed on Inertia whose permission the actor holds on some node, in the registry's order, and
 * the navigation entries the actor may open, in render order. It is not content: the pipeline
 * strips nothing from it, and it carries no content keys.
 */
#[Experimental]
final readonly class ActionList implements Result
{
    /**
     * @param  list<ListedAction>  $actions
     * @param  list<ListedNavEntry>  $navigation
     */
    public function __construct(
        public array $actions,
        public array $navigation,
    ) {}
}
