---
title: Envelope JSON
weight: 48
description: "The envelope fields a caller sends with a write, envelope.v1.json: the idempotency key, correlation id, wait level, dry run, on-behalf-of chain and provenance, their defaults, and how a surface builds the Envelope from them."
---

# Envelope JSON

<!-- extension-point: packages/contracts/resources/schemas/envelope.v1.json -->

A write is a command and an envelope (PRD 6.1). The surface builds the envelope, never the action, and most of it never comes from the caller: the actor comes from the transport's authentication, and the surface and the issuer kind from where and how the call arrived. The rest the caller sends. Its PHP form is `Cbox\Cms\Contracts\Envelope\RequestEnvelope`, and its JSON form is contract version 1, described by the JSON Schema [`envelope.v1.json`](../../packages/contracts/resources/schemas/envelope.v1.json) and read and written by the generated codec `Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1`. All of it is `#[Experimental]`.

## The fields

| Key | Default | What it holds |
|---|---|---|
| `idempotency_key` | required | the caller's key, 1 to 255 visible ASCII characters; the same key with the same command gives the first call's result |
| `correlation_id` | `null` | the id that ties the call together across surfaces and jobs, such as a trace id; when it is `null` the surface makes one |
| `wait_level` | `commit` | how long to wait: `commit`, `origin`, `edge`, `verified` or `propagated` (PRD 8.4) |
| `dry_run` | `false` | compute the plan, the blast radius and the receipt without committing |
| `on_behalf_of` | `[]` | whom the actor acts for, in order, each an actor id; an actor appears at most once, and never the actor itself |
| `provenance` | `{}` | for agents and ingestion (PRD 5.5): `model` (`name` and `version`, or `null`), `parameters` (each `name` and `value`), `prompt` (a reference, or `null`) and `sources`; parameters and a prompt need a model |

A key left out has its default, and the codec writes every key, sorted and without whitespace. The reason of a withdrawal or a redaction is part of the command, not the envelope, so it is read with the command. `RequestEnvelope::envelope()` builds the `Envelope` of an exposed surface from these fields, the surface, the issuer kind and the actor, with the correlation id the surface made when the request has none.

## The codec is generated

`composer generate:protocol` writes the codec from the schema, bound to `RequestEnvelope`, `Provenance`, `GenerationModel` and `ModelParameter` and to the value objects of the contracts, so no code serialises an envelope by hand (GUARDRAILS 2.2); `composer check:generated` and the Arch suite hold it to that. `decode()` refuses a document with `DecodingFailed`, `json_invalid` with the path of the value, for a missing idempotency key, an unknown key such as `actor`, a value the schema refuses, and a chain or provenance the contracts refuse.

The example is in the `Unit` suite:

<!-- example: examples/Unit/Protocol/RequestEnvelopeTest.php -->
```php
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
```
