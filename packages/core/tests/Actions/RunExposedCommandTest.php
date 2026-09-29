<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\Outcome;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\IssuerKind as EnvelopeIssuer;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\RequestEnvelope;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Core\Pipeline\Domain\Dto\StaleRead;
use Cbox\Cms\Core\Pipeline\Domain\Dto\VersionConflict;
use Cbox\Cms\Core\Tests\Pipeline\ExposedWorld;
use Cbox\Cms\Core\Tests\Pipeline\PipelineWorld;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;

/*
 * RunExposedCommand (GUARDRAILS 2.1, PRD 6.1, 6.2) called directly with its DTO, the test-only
 * command probe.rename and the fakes of its ports (GUARDRAILS 9): the identity as the credential
 * verifier, the fake AccessContexts and the command pipeline of the PipelineWorld. It covers success
 * with the envelope the action builds, every rejection before the pipeline, the rejections the
 * pipeline gives back, the conflict at commit and the dry run.
 */

/**
 * @return list<string> each error as "<code> <path>"
 */
function exposedErrors(WriteResult $result): array
{
    return array_map(
        static fn (CatalogError $error): string => $error->code->value.' '.($error->path?->toString() ?? '-'),
        $result->errors,
    );
}

function exposedRequest(string $key = 'exposed-1', ?CorrelationId $correlation = null, bool $dryRun = false, ActorId ...$onBehalfOf): RequestEnvelope
{
    return new RequestEnvelope(new IdempotencyKey($key), $correlation, WaitLevel::Commit, $dryRun, array_values($onBehalfOf));
}

it('runs the command through the pipeline as the credential\'s actor, with the envelope built from the caller\'s fields', function (): void {
    $exposed = new ExposedWorld;

    $result = $exposed->action()->run($exposed->call($exposed->credential(), exposedRequest('exposed-success'), surface: Surface::Inertia));

    $envelope = $exposed->world->committer->pending[0]->envelope;

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($result->errors)->toBe([])
        ->and($exposed->world->committer->pending)->toHaveCount(1)
        ->and($envelope->surface)->toBe(IssuingSurface::Inertia)
        ->and($envelope->issuerKind)->toBe(EnvelopeIssuer::System)
        ->and($envelope->actor->equals($exposed->service))->toBeTrue()
        ->and($envelope->onBehalfOf->chain)->toBe([])
        ->and($envelope->idempotencyKey->value)->toBe('exposed-success')
        ->and($envelope->correlationId->value)->toBe(new FakeIdGenerator(ExposedWorld::CORRELATION_SEED)->next()->value)
        ->and($envelope->dryRun)->toBeFalse()
        ->and($exposed->world->committer->pending[0]->access->classificationAccess)->toBe(ClassificationAccess::Internal);
});

it('keeps the caller\'s correlation id, and calls an agent\'s credential an agent', function (): void {
    $exposed = new ExposedWorld;

    $result = $exposed->action()->run($exposed->call($exposed->credential(IssuerKind::Agent), exposedRequest('exposed-agent', new CorrelationId('from-the-caller'))));

    $envelope = $exposed->world->committer->pending[0]->envelope;

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and($envelope->surface)->toBe(IssuingSurface::Rest)
        ->and($envelope->issuerKind)->toBe(EnvelopeIssuer::Agent)
        ->and($envelope->correlationId->value)->toBe('from-the-caller');
});

it('takes the on-behalf-of chain from the credential, whether the caller repeats it or leaves it out', function (bool $repeat): void {
    $exposed = new ExposedWorld;
    $editor = $exposed->world->editor;
    $request = $repeat ? exposedRequest('exposed-chain', null, false, $editor) : exposedRequest('exposed-chain');

    $result = $exposed->action()->run($exposed->call($exposed->credential(IssuerKind::Service, $editor), $request));

    $pending = $exposed->world->committer->pending[0];
    $principal = $pending->access->principal;

    expect($result->outcome())->toBe(Outcome::Committed)
        ->and(array_map(static fn (ActorId $actor): string => $actor->toString(), $pending->envelope->onBehalfOf->chain))->toBe([$editor->toString()])
        ->and($principal)->toBeInstanceOf(ActorPrincipal::class)
        ->and($principal instanceof ActorPrincipal ? $principal->onBehalfOf : [])->toEqual([$editor]);
})->with([
    'repeated in the envelope' => [true],
    'left out of the envelope' => [false],
]);

it('rejects an envelope that names another chain than the credential, before the pipeline', function (): void {
    $exposed = new ExposedWorld;

    $result = $exposed->action()->run($exposed->call($exposed->credential(), exposedRequest('exposed-other-chain', null, false, $exposed->world->editor)));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(exposedErrors($result))->toBe(['unauthorized -'])
        ->and($result->errors[0]->message)->toContain('another on-behalf-of chain than the credential carries')
        ->and($exposed->world->committer->pending)->toBe([])
        ->and($exposed->contexts->asked)->toBe([]);
});

it('rejects a call without a credential as unauthorized, and one with a refused credential with the verifier\'s code', function (): void {
    $exposed = new ExposedWorld;
    $unknown = $exposed->credential();
    $exposed->world->identity->changeState($exposed->service, ActorState::Deactivated);

    $anonymous = $exposed->action()->run($exposed->call(null, exposedRequest('exposed-anonymous')));
    $malformed = $exposed->action()->run($exposed->call(new TransportCredential('not-a-token'), exposedRequest('exposed-malformed')));
    $inactive = $exposed->action()->run($exposed->call($unknown, exposedRequest('exposed-inactive', null, false)));

    expect(exposedErrors($anonymous))->toBe(['unauthorized -'])
        ->and($anonymous->errors[0]->message)->toContain('carries no credential')
        ->and(exposedErrors($malformed))->toBe(['credential_malformed -'])
        ->and(exposedErrors($inactive))->toBe(['actor_not_active -'])
        ->and($anonymous->receipt->waitLevel)->toBe(WaitLevel::Commit)
        ->and($exposed->world->committer->pending)->toBe([])
        ->and($exposed->contexts->asked)->toBe([]);
});

it('rejects a command document its codec refuses, at the path of the value, after the access context is known', function (string $document, array $errors): void {
    $exposed = new ExposedWorld;

    $result = $exposed->action()->run($exposed->call($exposed->credential(), exposedRequest('exposed-unreadable'), $document));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(exposedErrors($result))->toBe($errors)
        ->and($exposed->world->committer->pending)->toBe([])
        ->and($exposed->contexts->asked)->toHaveCount(1);
})->with([
    'not JSON' => ['{"entry":', ['json_malformed -']],
    'a field that is not a field value' => [
        '{"entry":"'.PipelineWorld::ENTRY.'","fields":{"label":1.5},"home":"'.PipelineWorld::HOME.'","type":"'.PipelineWorld::ENTRY.'"}',
        ['json_invalid fields.label'],
    ],
    'a missing key' => ['{"entry":"'.PipelineWorld::ENTRY.'","fields":{},"home":"'.PipelineWorld::HOME.'"}', ['json_invalid type']],
]);

it('gives back the pipeline\'s rejection, field errors with their paths in the command', function (): void {
    $exposed = new ExposedWorld;

    $result = $exposed->action()->run($exposed->call($exposed->credential(), exposedRequest('exposed-invalid'), $exposed->document(new FieldValues(new FieldMap(new NamedValue(new FieldHandle('colour'), new TextValue('red')))))));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(exposedErrors($result))->toBe(['validation_failed -', 'validation_required fields.label', 'validation_unknown_field fields.colour'])
        ->and($exposed->world->committer->pending)->toBe([]);
});

it('gives back a version conflict at commit', function (): void {
    $exposed = new ExposedWorld;
    $exposed->world->commitWith(new VersionConflict(new StaleRead($exposed->world->entry(), null, new AggregateVersion(1))));

    $result = $exposed->action()->run($exposed->call($exposed->credential(), exposedRequest('exposed-conflict')));

    expect($result->outcome())->toBe(Outcome::Rejected)
        ->and(exposedErrors($result))->toBe(['version_conflict -']);
});

it('runs a dry run, which commits nothing', function (): void {
    $exposed = new ExposedWorld;

    $result = $exposed->action()->run($exposed->call($exposed->credential(), exposedRequest('exposed-dry-run', null, true)));

    expect($result->outcome())->toBe(Outcome::DryRun)
        ->and($result->dryRun)->not->toBeNull()
        ->and($exposed->world->committer->pending)->toBe([]);
});
