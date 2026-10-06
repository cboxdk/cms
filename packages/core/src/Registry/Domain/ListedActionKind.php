<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * What an action of action.list handles (GUARDRAILS 2.1, PRD 13.4): a command, a write the panel
 * runs through the command's form, or a query, a read. The registry's ActionKind tells a write
 * action from a query action by its interface; this is the same fact as a caller reads it, named
 * by what the action takes.
 */
#[Experimental]
enum ListedActionKind: string
{
    case Command = 'command';
    case Query = 'query';

    public static function of(ActionKind $kind): self
    {
        return match ($kind) {
            ActionKind::Write => self::Command,
            ActionKind::Query => self::Query,
        };
    }
}
