<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Phpstan;

use Cbox\Cms\Contracts\Attributes\Internal;
use PHPStan\Analyser\Scope;

/**
 * Where TransactionCallsRule and SavepointStringsRule apply (GUARDRAILS 4.1, PRD 4.2): the
 * Actions and Jobs layers. The command bus owns the one transaction of a command, so an
 * action or a job never opens, commits or rolls back a transaction, and never uses a
 * savepoint or a nested transaction. Test code is not checked.
 */
#[Internal]
final class TransactionScope
{
    /**
     * The layers that may not manage transactions.
     *
     * @var list<string>
     */
    public const array LAYERS = ['Actions', 'Jobs'];

    public static function applies(Scope $scope): bool
    {
        $namespace = $scope->getNamespace() ?? '';

        return ! LayerScope::isTestCode($namespace)
            && in_array(LayerScope::layerOf($namespace), self::LAYERS, true);
    }
}
