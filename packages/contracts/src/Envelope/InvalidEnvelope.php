<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Ids\ActorId;
use InvalidArgumentException;

/**
 * An envelope, or a value of one, that breaks its invariants.
 */
#[Experimental]
final class InvalidEnvelope extends InvalidArgumentException
{
    public static function missingIdempotencyKey(IssuingSurface $surface): self
    {
        return new self(sprintf(
            'A call through the %s surface needs the idempotency key its caller sent; only an internal issuer derives one from its unit of work.',
            $surface->value,
        ));
    }

    public static function keyFromInternalIssuer(IssuingSurface $surface): self
    {
        return new self(sprintf(
            'The internal issuer %s has no caller to send an idempotency key; build its envelope from its unit of work.',
            $surface->value,
        ));
    }

    public static function issuerKind(IssuingSurface $surface, IssuerKind $required, IssuerKind $given): self
    {
        return new self(sprintf(
            'The internal issuer %s runs with the issuer kind %s, not %s.',
            $surface->value,
            $required->value,
            $given->value,
        ));
    }

    public static function actorInOwnChain(ActorId $actor): self
    {
        return new self(sprintf('The actor %s cannot act on behalf of itself.', $actor->toString()));
    }

    public static function repeatedPrincipal(ActorId $principal): self
    {
        return new self(sprintf('The actor %s appears twice in one on-behalf-of chain.', $principal->toString()));
    }

    public static function correlationId(string $value): self
    {
        return new self(sprintf(
            'A correlation id is 1 to %d visible ASCII characters, got "%s".',
            128,
            self::shown($value),
        ));
    }

    public static function unitOfWork(string $value): self
    {
        return new self(sprintf(
            'A unit of work is 1 to %d visible ASCII characters, such as "event:<event id>:<step>", got "%s".',
            255,
            self::shown($value),
        ));
    }

    public static function provenanceText(string $what, string $value): self
    {
        return new self(sprintf(
            'A %s is UTF-8 text without control characters, not empty and not too long, got "%s".',
            $what,
            self::shown($value),
        ));
    }

    public static function provenanceWithoutModel(): self
    {
        return new self('Provenance with model parameters or a prompt needs the model they belong to.');
    }

    public static function duplicateProvenance(string $what, string $value): self
    {
        return new self(sprintf('The %s "%s" appears twice in one provenance.', $what, self::shown($value)));
    }

    public static function reasonCode(string $value): self
    {
        return new self(sprintf(
            'A reason code is lowercase snake_case of at most %d bytes, got "%s".',
            63,
            self::shown($value),
        ));
    }

    /**
     * The text itself is left out: it can hold personal data.
     */
    public static function reasonText(): self
    {
        return new self(sprintf(
            'The free text of a reason is 1 to %d bytes of UTF-8 and not only white space.',
            4000,
        ));
    }

    private static function shown(string $value): string
    {
        $cut = strlen($value) > 64 ? substr($value, 0, 64).'...' : $value;

        return addcslashes($cut, "\0..\37\177..\377\"\\");
    }
}
