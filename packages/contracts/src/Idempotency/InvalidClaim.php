<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Idempotency;

use Cbox\Cms\Contracts\Attributes\Experimental;
use LogicException;

/**
 * The IdempotencyStore was used against its protocol: outside a transaction, or complete() with a
 * token that the current transaction does not hold as a fresh claim. It is a bug in the caller,
 * not a result.
 */
#[Experimental]
final class InvalidClaim extends LogicException
{
    public static function outsideTransaction(string $operation): self
    {
        return new self(sprintf(
            'IdempotencyStore::%s() runs inside the caller\'s command transaction, and the connection has none open. A claim lasts until that transaction ends.',
            $operation,
        ));
    }

    public static function notHeld(ClaimToken $token): self
    {
        return new self(sprintf(
            'This transaction holds no fresh claim on %s. complete() takes the token of a Fresh claim made in the same transaction, while it is still open.',
            self::named($token),
        ));
    }

    public static function alreadyCompleted(ClaimToken $token): self
    {
        return new self(sprintf(
            'The claim on %s was already completed in this transaction. A claim records one changeset.',
            self::named($token),
        ));
    }

    /**
     * The store's claims need to see commits made while they waited, which only READ COMMITTED
     * gives: under REPEATABLE READ or SERIALIZABLE the snapshot is older than the wait.
     */
    public static function isolationLevel(string $level): self
    {
        return new self(sprintf(
            'IdempotencyStore::claim() needs the command transaction at READ COMMITTED, and it is %s. A statement after the claim must see a commit made while the claim waited.',
            strtoupper($level),
        ));
    }

    private static function named(ClaimToken $token): string
    {
        return sprintf(
            'key "%s" of %s "%s" for command type %s',
            $token->key->value,
            $token->scope->kind->value,
            $token->scope->principal->value,
            $token->scope->commandType->value,
        );
    }
}
