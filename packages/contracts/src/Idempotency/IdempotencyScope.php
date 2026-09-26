<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;

/**
 * The scope an idempotency key is unique in (PRD 6.1): an actor or a source, plus the command
 * type. The same key under another actor, source or command type is a different key.
 *
 * The principal is the id of the actor or the source, and the kind says which of the two it is. The
 * command type is the name from #[Command], without the version.
 */
#[Experimental]
final readonly class IdempotencyScope
{
    public function __construct(
        public PrincipalKind $kind,
        public PrincipalId $principal,
        public CommandName $commandType,
    ) {}

    public static function forActor(PrincipalId $actor, CommandName $commandType): self
    {
        return new self(PrincipalKind::Actor, $actor, $commandType);
    }

    public static function forSource(PrincipalId $source, CommandName $commandType): self
    {
        return new self(PrincipalKind::Source, $source, $commandType);
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->principal->equals($other->principal)
            && $this->commandType->equals($other->commandType);
    }
}
