<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Entries;

use Cbox\Cms\Contracts\Pipeline\Aggregates;
use Cbox\Cms\Contracts\Pipeline\Command;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Contracts\Plans\Plan;
use Closure;
use LogicException;
use Override;

/**
 * A write action that runs what a test lets happen meanwhile right after the action it wraps has
 * read, such as another call committing on another connection, so the call goes on from reads that
 * are stale by then. Everything else is the wrapped action's.
 *
 * @implements WriteAction<Command, Aggregates>
 */
final readonly class InterleavedAction implements WriteAction
{
    /**
     * @param  object  $action  a write action for the command's class
     * @param  Closure(): void  $meanwhile
     */
    public function __construct(
        private object $action,
        private Closure $meanwhile,
    ) {}

    #[Override]
    public function resolve(Command $command): Aggregates
    {
        $action = $this->action;

        if (! $action instanceof WriteAction) {
            throw $this->notAnAction();
        }

        $aggregates = $action->resolve($command);
        ($this->meanwhile)();

        return $aggregates;
    }

    #[Override]
    public function plan(Command $command, Aggregates $aggregates): Plan
    {
        $action = $this->action;

        if (! $action instanceof WriteAction) {
            throw $this->notAnAction();
        }

        return $action->plan($command, $aggregates);
    }

    private function notAnAction(): LogicException
    {
        return new LogicException(sprintf('%s is not a write action.', $this->action::class));
    }
}
