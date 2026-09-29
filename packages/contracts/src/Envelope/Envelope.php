<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\IdempotencyScope;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Ids\PrincipalId;

/**
 * Everything about a write that is not the command (GUARDRAILS 2.1, PRD 5.5, 6.1): who issues it
 * and for whom, through which surface, with which idempotency key, provenance and reason, whether
 * it is a dry run, how long the caller waits and the correlation id.
 *
 * The surface builds the envelope, never the action: the actor comes from the transport's
 * authentication, never from input fields. A call through an exposed surface is built with
 * external() and carries the idempotency key its caller sent; the key is required. A call from an
 * internal issuer, a job, the scheduler, a subscriber, a sidecar or a seed, is built with
 * internal() from its unit of work, and its key is derived from that unit, so the same unit
 * always gives the same key.
 *
 * The wait level is Commit unless the caller asks for more (PRD 8.4). The actor never appears in
 * its own on-behalf-of chain.
 */
#[Experimental]
final readonly class Envelope
{
    private function __construct(
        public IssuingSurface $surface,
        public IssuerKind $issuerKind,
        public ActorId $actor,
        public OnBehalfOf $onBehalfOf,
        public IdempotencyKey $idempotencyKey,
        public ?UnitOfWork $unitOfWork,
        public CorrelationId $correlationId,
        public Provenance $provenance,
        public ?Reason $reason,
        public bool $dryRun,
        public WaitLevel $waitLevel,
    ) {
        if ($onBehalfOf->contains($actor)) {
            throw InvalidEnvelope::actorInOwnChain($actor);
        }
    }

    /**
     * The envelope of a call through an exposed surface, with the idempotency key its caller sent.
     */
    public static function external(
        IssuingSurface $surface,
        IssuerKind $issuerKind,
        ActorId $actor,
        IdempotencyKey $idempotencyKey,
        CorrelationId $correlationId,
        OnBehalfOf $onBehalfOf = new OnBehalfOf,
        Provenance $provenance = new Provenance,
        ?Reason $reason = null,
        bool $dryRun = false,
        WaitLevel $waitLevel = WaitLevel::Commit,
    ): self {
        if (! $surface->isExternal()) {
            throw InvalidEnvelope::keyFromInternalIssuer($surface);
        }

        return new self($surface, $issuerKind, $actor, $onBehalfOf, $idempotencyKey, null, $correlationId, $provenance, $reason, $dryRun, $waitLevel);
    }

    /**
     * The envelope of a call from an internal issuer, with the idempotency key derived from its
     * unit of work (deriveKey()).
     */
    public static function internal(
        IssuingSurface $surface,
        IssuerKind $issuerKind,
        ActorId $actor,
        UnitOfWork $unitOfWork,
        CorrelationId $correlationId,
        OnBehalfOf $onBehalfOf = new OnBehalfOf,
        Provenance $provenance = new Provenance,
        ?Reason $reason = null,
        bool $dryRun = false,
        WaitLevel $waitLevel = WaitLevel::Commit,
    ): self {
        if ($surface->isExternal()) {
            throw InvalidEnvelope::missingIdempotencyKey($surface);
        }

        return new self($surface, $issuerKind, $actor, $onBehalfOf, self::deriveKey($surface, $unitOfWork), $unitOfWork, $correlationId, $provenance, $reason, $dryRun, $waitLevel);
    }

    /**
     * The idempotency key of an internal issuer's unit of work: the issuer, a colon and the
     * SHA-256 of the unit in hex, such as "subscriber:3f5a...". The same issuer and unit always
     * give the same key; another issuer or unit gives another.
     */
    public static function deriveKey(IssuingSurface $surface, UnitOfWork $unitOfWork): IdempotencyKey
    {
        if ($surface->isExternal()) {
            throw InvalidEnvelope::missingIdempotencyKey($surface);
        }

        return new IdempotencyKey($surface->value.':'.hash('sha256', $unitOfWork->value));
    }

    /**
     * The scope the idempotency key is unique in for the command type (PRD 6.1): the actor and
     * the command's name.
     */
    public function idempotencyScope(CommandName $command): IdempotencyScope
    {
        return IdempotencyScope::forActor(new PrincipalId($this->actor->toString()), $command);
    }

    /**
     * The actor followed by its on-behalf-of chain, in order.
     *
     * @return list<ActorId>
     */
    public function principals(): array
    {
        return [$this->actor, ...$this->onBehalfOf->chain];
    }

    /**
     * The principal at the end of the chain: the person behind a token or an agent, or the actor
     * when it acts for itself. Four-eyes rules are judged on it.
     */
    public function responsible(): ActorId
    {
        return $this->onBehalfOf->chain === []
            ? $this->actor
            : array_last($this->onBehalfOf->chain);
    }

    /**
     * What the audit chain and events may record of the reason: its code, never its free text.
     */
    public function auditReason(): ?ReasonCode
    {
        return $this->reason?->forAudit();
    }
}
