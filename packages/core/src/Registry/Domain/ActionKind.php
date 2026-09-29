<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What an action is (GUARDRAILS 2.1): a write action, which implements WriteAction and handles a
 * command, or a query action, which implements QueryAction and handles a query.
 */
#[Experimental]
enum ActionKind: string
{
    case Write = 'write';
    case Query = 'query';

    /**
     * The attribute the class an action of this kind handles carries.
     */
    public function input(): string
    {
        return match ($this) {
            self::Write => 'command',
            self::Query => 'query',
        };
    }
}
