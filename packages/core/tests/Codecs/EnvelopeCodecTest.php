<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Codecs;

use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\GenerationModel;
use Cbox\Cms\Contracts\Envelope\ModelParameter;
use Cbox\Cms\Contracts\Envelope\PromptReference;
use Cbox\Cms\Contracts\Envelope\Provenance;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Envelope\SourceReference;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Core\Codecs\Boundary\Generated\EnvelopeCodecV1;

/*
 * The envelope a caller sends with a write (PRD 6.1), envelope.v1.json, through its generated codec
 * (GUARDRAILS 2.2): a RequestEnvelope round-trips to equal values, its JSON is canonical with every
 * key and validates against the schema with an independent validator, a missing key has its
 * default, and the codec refuses what the schema or the envelope refuses.
 */

function envelopeCodec(): EnvelopeCodecV1
{
    return new EnvelopeCodecV1;
}

function agentEnvelope(): RequestEnvelope
{
    return new RequestEnvelope(
        idempotencyKey: new IdempotencyKey('order-1042'),
        correlationId: new CorrelationId('trace-7f3a'),
        waitLevel: WaitLevel::Edge,
        dryRun: true,
        onBehalfOf: [ActorId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a10'), ActorId::fromString('0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a11')],
        provenance: new Provenance(
            new GenerationModel('writer', '2026-03'),
            [new ModelParameter('temperature', '0.2'), new ModelParameter('max_tokens', '800')],
            new PromptReference('prompts/summary/v3'),
            [new SourceReference('https://example.test/feed/42'), new SourceReference('feed:item:9')],
        ),
    );
}

const AGENT_ENVELOPE = '{"correlation_id":"trace-7f3a","dry_run":true,"idempotency_key":"order-1042","on_behalf_of":["0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a10","0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a11"],"provenance":{"model":{"name":"writer","version":"2026-03"},"parameters":[{"name":"max_tokens","value":"800"},{"name":"temperature","value":"0.2"}],"prompt":"prompts/summary/v3","sources":["https://example.test/feed/42","feed:item:9"]},"wait_level":"edge"}';

const DEFAULT_ENVELOPE = '{"correlation_id":null,"dry_run":false,"idempotency_key":"order-1042","on_behalf_of":[],"provenance":{"model":null,"parameters":[],"prompt":null,"sources":[]},"wait_level":"commit"}';

it('writes an agent\'s envelope as canonical JSON that validates against envelope.v1.json, and reads it back equal', function (): void {
    $json = envelopeCodec()->encode(agentEnvelope(), ClassificationAccess::Public);
    $decoded = envelopeCodec()->decode($json, ClassificationAccess::Public);

    expect($json)->toBe(AGENT_ENVELOPE)
        ->and(KernelSchema::errors('envelope.v1.json', $json))->toBe([])
        ->and($decoded)->toEqual(agentEnvelope())
        ->and(envelopeCodec()->encode($decoded, ClassificationAccess::Public))->toBe($json)
        ->and(EnvelopeCodecV1::VERSION)->toBe(1);
});

it('gives every key but the idempotency key its default, and writes every key', function (string $json): void {
    $decoded = envelopeCodec()->decode($json, ClassificationAccess::Public);

    expect($decoded)->toEqual(new RequestEnvelope(new IdempotencyKey('order-1042')))
        ->and(KernelSchema::errors('envelope.v1.json', $json))->toBe([])
        ->and(envelopeCodec()->encode($decoded, ClassificationAccess::Public))->toBe(DEFAULT_ENVELOPE);
})->with([
    'only the key' => ['{"idempotency_key":"order-1042"}'],
    'an empty provenance' => ['{"idempotency_key":"order-1042","provenance":{}}'],
    'every default written out' => [DEFAULT_ENVELOPE],
]);

it('refuses a document that breaks envelope.v1.json, as the schema does', function (string $json, ?string $path, string $reason): void {
    expect(Failures::described(static fn (): RequestEnvelope => envelopeCodec()->decode($json, ClassificationAccess::Public)))->toBe(['json_invalid', $path, $reason])
        ->and(KernelSchema::errors('envelope.v1.json', $json))->not->toBe([]);
})->with([
    'no idempotency key' => ['{"wait_level":"edge"}', 'idempotency_key', 'is missing, and the field is required'],
    'a key with a space' => ['{"idempotency_key":"order 1042"}', 'idempotency_key', 'is not valid: An idempotency key is 1 to 255 visible ASCII characters, without spaces, got "order 1042".'],
    'a null dry run' => ['{"idempotency_key":"order-1042","dry_run":null}', 'dry_run', 'is null, and the field is not nullable'],
    'an unknown wait level' => ['{"idempotency_key":"order-1042","wait_level":"forever"}', 'wait_level', 'is not one of commit, origin, edge, verified, propagated'],
    'a principal that is not an actor id' => ['{"idempotency_key":"order-1042","on_behalf_of":["editor"]}', 'on_behalf_of[0]', 'is not a valid id: Expected a UUID in the form xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx with hex digits, got "editor".'],
    'an empty model name' => ['{"idempotency_key":"order-1042","provenance":{"model":{"name":"","version":"1"}}}', 'provenance.model.name', 'has 0 characters, fewer than the 1 the field requires'],
    'the actor as an unknown key' => ['{"idempotency_key":"order-1042","actor":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a10"}', null, 'has the key "actor", which is not a field of the contract'],
]);

it('refuses an envelope that the schema allows but the envelope does not, at the object that breaks it', function (string $json, ?string $path, string $reason): void {
    expect(Failures::described(static fn (): RequestEnvelope => envelopeCodec()->decode($json, ClassificationAccess::Public)))->toBe(['json_invalid', $path, 'breaks a rule of the contract: '.$reason])
        ->and(KernelSchema::errors('envelope.v1.json', $json))->toBe([]);
})->with([
    'a principal twice in the chain' => ['{"idempotency_key":"order-1042","on_behalf_of":["0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a10","0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a10"]}', null, 'The actor 0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a10 appears twice in one on-behalf-of chain.'],
    'a prompt without a model' => ['{"idempotency_key":"order-1042","provenance":{"prompt":"prompts/summary/v3"}}', 'provenance', 'Provenance with model parameters or a prompt needs the model they belong to.'],
    'a parameter twice' => ['{"idempotency_key":"order-1042","provenance":{"model":{"name":"writer","version":"1"},"parameters":[{"name":"t","value":"1"},{"name":"t","value":"2"}]}}', 'provenance', 'The parameter "t" appears twice in one provenance.'],
]);
