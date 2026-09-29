<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Identity;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An identity value that breaks its rules: a version or generation below 1, a chain that holds an
 * actor twice, a classification ceiling above what the issuer kind permits, or a credential that
 * cannot be issued.
 */
#[Experimental]
final class InvalidIdentity extends InvalidArgumentException
{
    public static function version(int $version): self
    {
        return new self(sprintf('An actor\'s version starts at %d, got %d.', Actor::FIRST_VERSION, $version));
    }

    public static function generation(int $generation): self
    {
        return new self(sprintf('A credential generation starts at %d, got %d.', CredentialGeneration::FIRST, $generation));
    }

    public static function chain(ActorId $actor): self
    {
        return new self(sprintf(
            'An on-behalf-of chain holds neither the actor itself nor an actor twice, but it holds %s again.',
            $actor->toString(),
        ));
    }

    public static function ceiling(IssuerKind $kind, ClassificationAccess $ceiling): self
    {
        return new self(sprintf(
            'A credential of the issuer kind %s has a classification ceiling of at most %s, got %s (PRD 2.31, 12.2).',
            $kind->value,
            $kind->maximumCeiling()->value,
            $ceiling->value,
        ));
    }

    public static function secret(int $length): self
    {
        return new self(sprintf(
            'The secret of a service credential is %d random bytes, got %d.',
            ServiceCredentialToken::SECRET_BYTES,
            $length,
        ));
    }

    public static function expiry(DateTimeImmutable $expiresAt, DateTimeImmutable $now): self
    {
        return new self(sprintf(
            'A credential is issued with an expiry after the time it is issued at, %s, got %s.',
            $now->format(DATE_RFC3339_EXTENDED),
            $expiresAt->format(DATE_RFC3339_EXTENDED),
        ));
    }

    public static function unknownActor(ActorId $actor): self
    {
        return new self(sprintf('No actor has the id %s.', $actor->toString()));
    }

    public static function notServiceActor(ActorId $actor, ActorClass $class): self
    {
        return new self(sprintf(
            'A service credential is issued only for an actor of the class service, but %s is of the class %s (PRD 5.16).',
            $actor->toString(),
            $class->value,
        ));
    }

    public static function inactiveActor(ActorId $actor, ActorState $state): self
    {
        return new self(sprintf(
            'A credential is issued only for an active actor and on behalf of active actors, but %s is %s.',
            $actor->toString(),
            $state->value,
        ));
    }

    public static function deprovisioned(ActorId $actor): self
    {
        return new self(sprintf('The actor %s is deprovisioned, which is final (PRD 6.4).', $actor->toString()));
    }

    public static function mismatch(string $what): self
    {
        return new self(sprintf('The actors given for the credential do not match its %s.', $what));
    }
}
