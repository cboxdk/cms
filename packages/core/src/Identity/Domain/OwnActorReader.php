<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Identity\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Identity\Domain\Dto\ActorMe;

/**
 * What actor.me reads (PRD 5.16, 13.4), in the read transaction under its actor context: the
 * context's own actor, its profile and the grants it holds that have not ended. The actor is the
 * context's, never a parameter, so a read can give nothing but the reader's own self; without an
 * actor context there is nothing to read, and own() gives null.
 */
#[Internal]
interface OwnActorReader
{
    public function own(): ?ActorMe;
}
