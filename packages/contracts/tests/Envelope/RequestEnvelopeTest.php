<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Envelope;

use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\GenerationModel;
use Cbox\Cms\Contracts\Envelope\InvalidEnvelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\OnBehalfOf;
use Cbox\Cms\Contracts\Envelope\Provenance;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Ids\ActorId;

/*
 * The fields of an envelope that a caller sends (PRD 6.1): a surface reads them from the request
 * and builds the Envelope with the actor, the surface and the issuer kind it knows from the
 * transport, never from the request.
 */

function requestActor(int $n): ActorId
{
    return ActorId::fromString(sprintf('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a%02d', $n));
}

it('waits for commit, is no dry run and has no chain, provenance or correlation id unless the caller sends them', function (): void {
    $request = new RequestEnvelope(new IdempotencyKey('order-1042'));

    expect($request->waitLevel)->toBe(WaitLevel::Commit)
        ->and($request->dryRun)->toBeFalse()
        ->and($request->onBehalfOf)->toBe([])
        ->and($request->provenance)->toEqual(new Provenance)
        ->and($request->correlationId)->toBeNull();
});

it('builds the envelope of an exposed surface with the actor from the transport and the correlation id the surface made', function (): void {
    $request = new RequestEnvelope(
        idempotencyKey: new IdempotencyKey('order-1042'),
        waitLevel: WaitLevel::Edge,
        dryRun: true,
        onBehalfOf: [requestActor(11), requestActor(12)],
        provenance: new Provenance(new GenerationModel('writer', '1')),
    );
    $envelope = $request->envelope(IssuingSurface::Rest, IssuerKind::Agent, requestActor(10), new CorrelationId('made-by-surface'));

    expect($envelope)->toEqual(Envelope::external(
        surface: IssuingSurface::Rest,
        issuerKind: IssuerKind::Agent,
        actor: requestActor(10),
        idempotencyKey: new IdempotencyKey('order-1042'),
        correlationId: new CorrelationId('made-by-surface'),
        onBehalfOf: new OnBehalfOf(requestActor(11), requestActor(12)),
        provenance: new Provenance(new GenerationModel('writer', '1')),
        dryRun: true,
        waitLevel: WaitLevel::Edge,
    ))->and($envelope->responsible())->toEqual(requestActor(12));
});

it('keeps the correlation id the caller sent', function (): void {
    $request = new RequestEnvelope(new IdempotencyKey('order-1042'), new CorrelationId('trace-7f3a'));

    expect($request->envelope(IssuingSurface::Mcp, IssuerKind::Human, requestActor(10), new CorrelationId('made'))->correlationId)
        ->toEqual(new CorrelationId('trace-7f3a'));
});

it('refuses a chain that repeats a principal, an actor in its own chain and an internal issuer', function (): void {
    $request = new RequestEnvelope(new IdempotencyKey('order-1042'), onBehalfOf: [requestActor(11)]);

    expect(static fn (): RequestEnvelope => new RequestEnvelope(new IdempotencyKey('k'), onBehalfOf: [requestActor(11), requestActor(11)]))
        ->toThrow(InvalidEnvelope::class, 'appears twice in one on-behalf-of chain')
        ->and(static fn (): Envelope => $request->envelope(IssuingSurface::Rest, IssuerKind::Human, requestActor(11), new CorrelationId('c')))
        ->toThrow(InvalidEnvelope::class)
        ->and(static fn (): Envelope => $request->envelope(IssuingSurface::Job, IssuerKind::System, requestActor(10), new CorrelationId('c')))
        ->toThrow(InvalidEnvelope::class);
});
