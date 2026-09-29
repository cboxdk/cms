<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Reads\Probe;

use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Pipeline\QueryAction;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;
use LogicException;

/**
 * The binding of a test-only query action, as the registry would match it: the action is typed
 * over its own query and result, and the binding holds any query action.
 */
final readonly class ProbeQueryBinding
{
    public static function of(object $action, string $query = 'probe.read', int $version = 2): QueryBinding
    {
        if (! $action instanceof QueryAction) {
            throw new LogicException(sprintf('%s is not a query action.', $action::class));
        }

        return new QueryBinding(new CommandName($query), $version, $action);
    }
}
