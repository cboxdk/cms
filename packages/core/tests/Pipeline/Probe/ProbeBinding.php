<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline\Probe;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\WriteAction;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use LogicException;

/**
 * The binding of probe.rename, version 1, to an action, as the registry gives it: the registry
 * matches the action to the command's class at run time, so the binding takes any write action
 * and the pipeline hands it only commands of that class.
 */
final readonly class ProbeBinding
{
    public static function of(object $action, int $version = 1): ActionBinding
    {
        if (! $action instanceof WriteAction) {
            throw new LogicException(sprintf('%s is not a write action.', $action::class));
        }

        return new ActionBinding(new CommandName('probe.rename'), $version, $action);
    }
}
