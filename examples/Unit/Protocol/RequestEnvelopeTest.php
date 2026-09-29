<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;

// A surface reads the envelope fields a caller sent with the generated codec of envelope.v1.json,
// and builds the Envelope with the actor it verified from the transport, never from the body.

it('reads an agent\'s envelope and builds the Envelope with the actor from the transport', function (): void {
    $request = new EnvelopeCodecV1()->decode(
        '{"idempotency_key":"order-1042","wait_level":"edge","on_behalf_of":["0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a11"],'
        .'"provenance":{"model":{"name":"writer","version":"2026-03"},"sources":["https://example.test/feed/42"]}}',
        ClassificationAccess::Public,
    );
    $agent = ActorId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a10');
    $envelope = $request->envelope(IssuingSurface::Rest, IssuerKind::Agent, $agent, new CorrelationId('made-by-rest'));

    expect($envelope->waitLevel)->toBe(WaitLevel::Edge)
        ->and($envelope->dryRun)->toBeFalse()
        ->and($envelope->correlationId->value)->toBe('made-by-rest')
        ->and($envelope->responsible()->toString())->toBe('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a11')
        ->and($envelope->provenance->model?->name)->toBe('writer');
});

it('writes every key with its default, and refuses a body without an idempotency key', function (): void {
    $codec = new EnvelopeCodecV1;

    expect($codec->encode($codec->decode('{"idempotency_key":"order-1042"}', ClassificationAccess::Public), ClassificationAccess::Public))
        ->toBe('{"correlation_id":null,"dry_run":false,"idempotency_key":"order-1042","on_behalf_of":[],"provenance":{"model":null,"parameters":[],"prompt":null,"sources":[]},"wait_level":"commit"}')
        ->and(static fn (): object => $codec->decode('{"wait_level":"edge"}', ClassificationAccess::Public))
        ->toThrow(DecodingFailed::class, '[json_invalid] idempotency_key: is missing, and the field is required.');
});
