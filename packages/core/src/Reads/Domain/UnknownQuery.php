<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Reads\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * A query that no query action handles, or whose registered action is not a query action. A
 * surface only builds queries the registry lists, so this is a stale registry cache or a bug, not
 * bad input: run cms:build.
 */
#[Internal]
final class UnknownQuery extends LogicException
{
    public static function noAction(string $queryClass): self
    {
        return new self(sprintf('No query action handles the query %s. Declare one with #[Action(handles: ...)] and run cms:build.', $queryClass));
    }

    public static function version(string $query, int $version): self
    {
        return new self(sprintf('The query %s has version %d. Versions start at 1.', $query, $version));
    }

    public static function notAQueryAction(string $queryClass, string $actionClass): self
    {
        return new self(sprintf('The action %s registered for the query %s does not implement QueryAction. Run cms:build.', $actionClass, $queryClass));
    }
}
