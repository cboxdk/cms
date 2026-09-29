<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\OnBehalfOf;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Ids\ActorId;

// A surface builds the envelope from what its transport authenticated and what the caller sent;
// an internal issuer builds it from its unit of work. The action never builds one.

it('carries the key a REST caller sent, and waits for commit by default', function (): void {
    $agent = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000000a');
    $editor = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000000b');

    $envelope = Envelope::external(
        IssuingSurface::Rest,
        IssuerKind::Agent,
        $agent,
        new IdempotencyKey('save-groceries-1'),
        new CorrelationId('4bf92f3577b34da6a3ce929d0e0e4736'),
        new OnBehalfOf($editor),
    );

    expect($envelope->idempotencyKey->value)->toBe('save-groceries-1')
        ->and($envelope->waitLevel)->toBe(WaitLevel::Commit)
        ->and($envelope->responsible()->equals($editor))->toBeTrue();
});

it('derives a subscriber\'s key from the event it handles, so a retry replays', function (): void {
    $system = ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-00000000000c');
    $unit = new UnitOfWork('event:01936f5e-8a2b-7c3d-9e4f-0000000000ff:reindex');

    $first = Envelope::internal(IssuingSurface::Subscriber, IssuerKind::System, $system, $unit, new CorrelationId('run-1'));
    $retry = Envelope::internal(IssuingSurface::Subscriber, IssuerKind::System, $system, $unit, new CorrelationId('run-2'));

    expect($first->idempotencyKey->equals($retry->idempotencyKey))->toBeTrue()
        ->and($first->idempotencyKey->value)->toStartWith('subscriber:');
});
