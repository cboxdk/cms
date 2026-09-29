<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Envelope;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Ids\ActorId;

/**
 * The fields of an envelope that a caller sends with a write through an exposed surface (PRD 6.1):
 * the idempotency key, the correlation id when the transport has one, the wait level, whether it
 * is a dry run, whom the actor acts for, and the provenance of agents and ingestion.
 *
 * The rest of the Envelope never comes from the request: the actor comes from the transport's
 * authentication, the surface and the issuer kind from where and how the call arrived. A surface
 * reads these fields and builds the Envelope with envelope(). A missing wait level is Commit, a
 * missing dry_run false, and a missing chain and provenance empty. The reason is part of the
 * command, not the envelope (PRD 6.1), so it is read with the command.
 *
 * Its JSON form is envelope.v1.json, read and written only by the generated codec,
 * Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1 (GUARDRAILS 2.2).
 */
#[Experimental]
final readonly class RequestEnvelope
{
    /** @var list<ActorId> */
    public array $onBehalfOf;

    /**
     * @param  list<ActorId>  $onBehalfOf  the chain in order, from the principal the actor acts for
     *                                     directly to the person at its end
     *
     * @throws InvalidEnvelope when an actor appears twice in the chain
     */
    public function __construct(
        public IdempotencyKey $idempotencyKey,
        public ?CorrelationId $correlationId = null,
        public WaitLevel $waitLevel = WaitLevel::Commit,
        public bool $dryRun = false,
        array $onBehalfOf = [],
        public Provenance $provenance = new Provenance,
    ) {
        $this->onBehalfOf = (new OnBehalfOf(...$onBehalfOf))->chain;
    }

    /**
     * The envelope of the call: these fields with the surface, the issuer kind and the actor the
     * surface knows from the transport. $made is the correlation id the surface made, used when the
     * request carries none.
     *
     * @throws InvalidEnvelope when the surface is an internal issuer or the actor is in its own chain
     */
    public function envelope(IssuingSurface $surface, IssuerKind $issuerKind, ActorId $actor, CorrelationId $made): Envelope
    {
        return Envelope::external(
            surface: $surface,
            issuerKind: $issuerKind,
            actor: $actor,
            idempotencyKey: $this->idempotencyKey,
            correlationId: $this->correlationId ?? $made,
            onBehalfOf: new OnBehalfOf(...$this->onBehalfOf),
            provenance: $this->provenance,
            dryRun: $this->dryRun,
            waitLevel: $this->waitLevel,
        );
    }
}
