<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The HTTP method of a route of the REST surface (GUARDRAILS 2.1, PRD 8.8): a write is a POST,
 * which changes state, and a read a GET, which does not.
 */
#[Experimental]
enum RestMethod: string
{
    case Get = 'GET';
    case Post = 'POST';

    public static function of(ActionKind $kind): self
    {
        return match ($kind) {
            ActionKind::Write => self::Post,
            ActionKind::Query => self::Get,
        };
    }
}
