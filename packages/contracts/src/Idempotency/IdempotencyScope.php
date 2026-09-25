<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Command;
use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The scope an idempotency key is unique in (PRD 6.1): an actor or a source, plus the command
 * type. The same key under another actor, source or command type is a different key.
 *
 * The principal is the actor's or source's reference as an opaque string, 1 to
 * MAX_PRINCIPAL_LENGTH visible ASCII characters. Typed actor and source ids replace it when the
 * actor model arrives in M1. The command type is the name from #[Command], without the version.
 */
#[Experimental]
final readonly class IdempotencyScope
{
    public const int MAX_PRINCIPAL_LENGTH = 255;

    private const string PRINCIPAL_PATTERN = '/\A[\x21-\x7E]{1,255}\z/';

    public function __construct(
        public PrincipalKind $kind,
        public string $principal,
        public string $commandType,
    ) {
        if (preg_match(self::PRINCIPAL_PATTERN, $principal) !== 1) {
            throw InvalidIdempotencyValue::principal($kind, $principal);
        }

        if (preg_match(Command::NAME_PATTERN, $commandType) !== 1) {
            throw InvalidIdempotencyValue::commandType($commandType);
        }
    }

    public static function forActor(string $actor, string $commandType): self
    {
        return new self(PrincipalKind::Actor, $actor, $commandType);
    }

    public static function forSource(string $source, string $commandType): self
    {
        return new self(PrincipalKind::Source, $source, $commandType);
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->principal === $other->principal
            && $this->commandType === $other->commandType;
    }
}
