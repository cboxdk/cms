<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * actor.me ran for a context without an actor the reader could read. The query authorizer refuses
 * the anonymous principal before the action runs, and a verified credential names an actor the
 * directory has, so this is a bug of the wiring, not an answer a caller gets.
 */
#[Internal]
final class OwnActorMissing extends LogicException
{
    public static function noActor(): self
    {
        return new self('actor.me ran for a context whose actor the OwnActorReader could not read. The query authorizer admits only an actor principal, so the reader runs under a context that names one.');
    }
}
