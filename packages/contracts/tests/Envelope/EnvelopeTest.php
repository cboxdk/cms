<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Envelope;

use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Consistency\WaitLevel;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Envelope\Envelope;
use Cbox\Cms\Contracts\Envelope\GenerationModel;
use Cbox\Cms\Contracts\Envelope\InvalidEnvelope;
use Cbox\Cms\Contracts\Envelope\IssuerKind;
use Cbox\Cms\Contracts\Envelope\IssuingSurface;
use Cbox\Cms\Contracts\Envelope\ModelParameter;
use Cbox\Cms\Contracts\Envelope\OnBehalfOf;
use Cbox\Cms\Contracts\Envelope\PromptReference;
use Cbox\Cms\Contracts\Envelope\Provenance;
use Cbox\Cms\Contracts\Envelope\Reason;
use Cbox\Cms\Contracts\Envelope\ReasonCode;
use Cbox\Cms\Contracts\Envelope\ReasonText;
use Cbox\Cms\Contracts\Envelope\SourceReference;
use Cbox\Cms\Contracts\Envelope\UnitOfWork;
use Cbox\Cms\Contracts\Idempotency\IdempotencyKey;
use Cbox\Cms\Contracts\Idempotency\PrincipalKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\CommandName;
use Cbox\Cms\Contracts\Plans\Mutations\RevisionCreated;
use FilesystemIterator;
use JsonSerializable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use SplFileInfo;
use Stringable;

/*
 * The Envelope (GUARDRAILS 2.1, PRD 5.5, 6.1): the on-behalf-of chain keeps its order, an external
 * surface needs the caller's idempotency key and an internal issuer derives one from its unit of
 * work, the wait level defaults to commit, and the reason's free text stays out of what the audit
 * chain and events get.
 */

function envelopeActor(int $n): ActorId
{
    return ActorId::fromString(sprintf('01936f5e-8a2b-7c3d-9e4f-%012d', $n));
}

function envelopeCorrelation(): CorrelationId
{
    return new CorrelationId('4bf92f3577b34da6a3ce929d0e0e4736');
}

function externalEnvelope(OnBehalfOf $onBehalfOf = new OnBehalfOf, ?Reason $reason = null): Envelope
{
    return Envelope::external(
        IssuingSurface::Rest,
        IssuerKind::Human,
        envelopeActor(1),
        new IdempotencyKey('order-1042'),
        envelopeCorrelation(),
        $onBehalfOf,
        reason: $reason,
    );
}

it('keeps the on-behalf-of chain in the order given, from the direct principal to the person at its end', function (): void {
    $envelope = externalEnvelope(new OnBehalfOf(envelopeActor(2), envelopeActor(3), envelopeActor(4)));

    expect(array_map(static fn (ActorId $actor): string => $actor->toString(), $envelope->principals()))->toBe([
        envelopeActor(1)->toString(),
        envelopeActor(2)->toString(),
        envelopeActor(3)->toString(),
        envelopeActor(4)->toString(),
    ])
        ->and($envelope->responsible()->equals(envelopeActor(4)))->toBeTrue()
        ->and($envelope->onBehalfOf->contains(envelopeActor(3)))->toBeTrue()
        ->and($envelope->onBehalfOf->contains(envelopeActor(5)))->toBeFalse()
        ->and($envelope->onBehalfOf->isEmpty())->toBeFalse();

    $reversed = externalEnvelope(new OnBehalfOf(envelopeActor(4), envelopeActor(3), envelopeActor(2)));

    expect($reversed->responsible()->equals(envelopeActor(2)))->toBeTrue()
        ->and($reversed->onBehalfOf->chain[0]->equals(envelopeActor(4)))->toBeTrue();
});

it('keeps principals given by name as a list', function (): void {
    $chain = new OnBehalfOf(...['token' => envelopeActor(2), 'person' => envelopeActor(3)]);

    expect(array_keys($chain->chain))->toBe([0, 1])
        ->and(externalEnvelope($chain)->responsible()->equals(envelopeActor(3)))->toBeTrue();
});

it('makes the actor responsible when it acts for itself', function (): void {
    $envelope = externalEnvelope();

    expect($envelope->onBehalfOf->isEmpty())->toBeTrue()
        ->and($envelope->responsible()->equals(envelopeActor(1)))->toBeTrue()
        ->and($envelope->principals())->toHaveCount(1);
});

it('refuses a chain that repeats a principal or holds the actor itself', function (): void {
    expect(static fn (): OnBehalfOf => new OnBehalfOf(envelopeActor(2), envelopeActor(3), envelopeActor(2)))
        ->toThrow(InvalidEnvelope::class, 'The actor '.envelopeActor(2)->toString().' appears twice in one on-behalf-of chain.')
        ->and(static fn (): Envelope => externalEnvelope(new OnBehalfOf(envelopeActor(2), envelopeActor(1))))
        ->toThrow(InvalidEnvelope::class, 'The actor '.envelopeActor(1)->toString().' cannot act on behalf of itself.');
});

it('carries the key the caller sent through an external surface', function (): void {
    foreach ([IssuingSurface::Rest, IssuingSurface::Inertia, IssuingSurface::Mcp, IssuingSurface::Cli] as $surface) {
        $envelope = Envelope::external($surface, IssuerKind::Human, envelopeActor(1), new IdempotencyKey('key-7'), envelopeCorrelation());

        expect($envelope->surface)->toBe($surface)
            ->and($envelope->idempotencyKey->value)->toBe('key-7')
            ->and($envelope->unitOfWork)->toBeNull();
    }
});

it('requires the caller\'s key on an external surface and never derives one for it', function (IssuingSurface $surface): void {
    expect(static fn (): Envelope => Envelope::internal($surface, IssuerKind::Human, envelopeActor(1), new UnitOfWork('event:1'), envelopeCorrelation()))
        ->toThrow(InvalidEnvelope::class, sprintf('A call through the %s surface needs the idempotency key its caller sent', $surface->value))
        ->and(static fn (): IdempotencyKey => Envelope::deriveKey($surface, new UnitOfWork('event:1')))
        ->toThrow(InvalidEnvelope::class, 'needs the idempotency key its caller sent');
})->with([IssuingSurface::Rest, IssuingSurface::Inertia, IssuingSurface::Mcp, IssuingSurface::Cli]);

it('refuses a caller\'s key from an internal issuer', function (IssuingSurface $surface): void {
    expect(static fn (): Envelope => Envelope::external($surface, IssuerKind::System, envelopeActor(1), new IdempotencyKey('key-7'), envelopeCorrelation()))
        ->toThrow(InvalidEnvelope::class, sprintf('The internal issuer %s has no caller to send an idempotency key', $surface->value));
})->with([IssuingSurface::Job, IssuingSurface::Scheduler, IssuingSurface::Subscriber, IssuingSurface::Sidecar, IssuingSurface::Seed, IssuingSurface::Maintenance]);

it('derives an internal issuer\'s key from its unit of work, the same for the same unit', function (): void {
    $unit = new UnitOfWork('event:01936f5e-8a2b-7c3d-9e4f-5a6b7c8d9e0f:purge');
    $first = Envelope::internal(IssuingSurface::Subscriber, IssuerKind::System, envelopeActor(1), $unit, envelopeCorrelation());
    $again = Envelope::internal(IssuingSurface::Subscriber, IssuerKind::System, envelopeActor(1), new UnitOfWork($unit->value), new CorrelationId('other'));

    expect($first->idempotencyKey->value)->toBe('subscriber:'.hash('sha256', $unit->value))
        ->and($first->idempotencyKey->equals($again->idempotencyKey))->toBeTrue()
        ->and($first->unitOfWork?->equals($unit))->toBeTrue()
        ->and(Envelope::deriveKey(IssuingSurface::Subscriber, $unit)->equals($first->idempotencyKey))->toBeTrue()
        ->and(Envelope::deriveKey(IssuingSurface::Subscriber, new UnitOfWork('event:other'))->equals($first->idempotencyKey))->toBeFalse()
        ->and(Envelope::deriveKey(IssuingSurface::Job, $unit)->equals($first->idempotencyKey))->toBeFalse();

    foreach ([IssuingSurface::Job, IssuingSurface::Scheduler, IssuingSurface::Sidecar, IssuingSurface::Seed] as $surface) {
        expect(Envelope::deriveKey($surface, $unit)->value)->toStartWith($surface->value.':');
    }
});

it('builds a maintenance envelope only with the issuer kind system, keyed by its unit of work', function (IssuerKind $kind): void {
    $unit = new UnitOfWork('install:operator');
    $maintenance = Envelope::internal(IssuingSurface::Maintenance, IssuerKind::System, envelopeActor(1), $unit, envelopeCorrelation());

    expect($maintenance->idempotencyKey->value)->toBe('maintenance:'.hash('sha256', 'install:operator'))
        ->and($maintenance->surface->surface())->toBeNull()
        ->and(IssuingSurface::Maintenance->requiredIssuerKind())->toBe(IssuerKind::System)
        ->and(IssuingSurface::Seed->requiredIssuerKind())->toBeNull()
        ->and(static fn (): Envelope => Envelope::internal(IssuingSurface::Maintenance, $kind, envelopeActor(1), $unit, envelopeCorrelation()))
        ->toThrow(InvalidEnvelope::class, sprintf('The internal issuer maintenance runs with the issuer kind system, not %s.', $kind->value));
})->with([IssuerKind::Human, IssuerKind::Agent, IssuerKind::Seed, IssuerKind::Migration, IssuerKind::Scheduler, IssuerKind::Sync]);

it('waits for commit unless the caller asks for more, and is no dry run unless asked', function (): void {
    $external = externalEnvelope();
    $internal = Envelope::internal(IssuingSurface::Seed, IssuerKind::Seed, envelopeActor(1), new UnitOfWork('seed:demo:1'), envelopeCorrelation());
    $asked = Envelope::external(IssuingSurface::Mcp, IssuerKind::Agent, envelopeActor(1), new IdempotencyKey('k'), envelopeCorrelation(), dryRun: true, waitLevel: WaitLevel::Origin);
    $askedInternal = Envelope::internal(IssuingSurface::Job, IssuerKind::Sync, envelopeActor(1), new UnitOfWork('job:1'), envelopeCorrelation(), dryRun: true, waitLevel: WaitLevel::Edge);

    expect($external->waitLevel)->toBe(WaitLevel::Commit)
        ->and($external->dryRun)->toBeFalse()
        ->and($internal->waitLevel)->toBe(WaitLevel::Commit)
        ->and($internal->dryRun)->toBeFalse()
        ->and($asked->waitLevel)->toBe(WaitLevel::Origin)
        ->and($asked->dryRun)->toBeTrue()
        ->and($askedInternal->waitLevel)->toBe(WaitLevel::Edge)
        ->and($askedInternal->dryRun)->toBeTrue()
        ->and($askedInternal->issuerKind)->toBe(IssuerKind::Sync);
});

it('carries every part the surface gives it', function (): void {
    $provenance = new Provenance(new GenerationModel('writer', '2026-08'), sources: [new SourceReference('https://example.test/a')]);
    $reason = new Reason(new ReasonCode('factual_error'));
    $external = Envelope::external(
        IssuingSurface::Mcp,
        IssuerKind::Agent,
        envelopeActor(1),
        new IdempotencyKey('k'),
        envelopeCorrelation(),
        new OnBehalfOf(envelopeActor(2)),
        $provenance,
        $reason,
    );
    $internal = Envelope::internal(
        IssuingSurface::Sidecar,
        IssuerKind::Agent,
        envelopeActor(1),
        new UnitOfWork('u'),
        envelopeCorrelation(),
        new OnBehalfOf(envelopeActor(2)),
        $provenance,
        $reason,
    );

    foreach ([$external, $internal] as $envelope) {
        expect($envelope->issuerKind)->toBe(IssuerKind::Agent)
            ->and($envelope->actor->equals(envelopeActor(1)))->toBeTrue()
            ->and($envelope->responsible()->equals(envelopeActor(2)))->toBeTrue()
            ->and($envelope->correlationId->equals(envelopeCorrelation()))->toBeTrue()
            ->and($envelope->provenance)->toBe($provenance)
            ->and($envelope->reason)->toBe($reason);
    }

    expect(static fn (): Envelope => Envelope::internal(IssuingSurface::Job, IssuerKind::System, envelopeActor(1), new UnitOfWork('u'), envelopeCorrelation(), new OnBehalfOf(envelopeActor(1))))
        ->toThrow(InvalidEnvelope::class, 'cannot act on behalf of itself');
});

it('scopes the idempotency key to the actor and the command type', function (): void {
    $scope = externalEnvelope()->idempotencyScope(new CommandName('entry.release'));

    expect($scope->kind)->toBe(PrincipalKind::Actor)
        ->and($scope->principal->value)->toBe(envelopeActor(1)->toString())
        ->and($scope->commandType->value)->toBe('entry.release');
});

it('gives the audit chain and events the reason code and never its free text', function (): void {
    $text = new ReasonText('The source asked us to remove Jane Doe\'s address.');
    $envelope = externalEnvelope(reason: new Reason(new ReasonCode('legal_request'), $text));

    expect($envelope->auditReason()?->value)->toBe('legal_request')
        ->and($envelope->reason?->forAudit()->equals(new ReasonCode('legal_request')))->toBeTrue()
        ->and($envelope->reason?->text?->classifiedContent())->toBe('The source asked us to remove Jane Doe\'s address.')
        ->and(externalEnvelope()->auditReason())->toBeNull()
        ->and(new Reason(new ReasonCode('typo'))->text)->toBeNull();

    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        new ReflectionClass(ReasonText::class)->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    expect($properties)->toBe([])
        ->and(new ReflectionClass(ReasonText::class)->implementsInterface(Stringable::class))->toBeFalse()
        ->and(new ReflectionClass(ReasonText::class)->implementsInterface(JsonSerializable::class))->toBeFalse()
        ->and(new ReflectionClass(ReasonCode::class)->getProperties())->toHaveCount(1);
});

it('keeps the reason out of every mutation, plan, result and receipt type', function (): void {
    $reachable = envelopeReachableTypes([
        'Cbox\Cms\Contracts\Plans',
        'Cbox\Cms\Contracts\Results',
        'Cbox\Cms\Contracts\Receipts',
        'Cbox\Cms\Contracts\Fields',
    ]);

    expect($reachable)->not->toBeEmpty()
        ->and($reachable)->toContain(RevisionCreated::class)
        ->and($reachable)->not->toContain(ReasonText::class)
        ->and($reachable)->not->toContain(Reason::class)
        ->and($reachable)->not->toContain(Envelope::class);
});

it('refuses a reason whose parts are malformed, without echoing the free text', function (): void {
    expect(static fn (): ReasonCode => new ReasonCode('Legal Request'))->toThrow(InvalidEnvelope::class, 'A reason code is lowercase snake_case of at most 63 bytes, got "Legal Request".')
        ->and(static fn (): ReasonCode => new ReasonCode(str_repeat('a', 64)))->toThrow(InvalidEnvelope::class, 'A reason code')
        ->and(new ReasonCode(str_repeat('a', 63))->value)->toHaveLength(63)
        ->and(static fn (): ReasonText => new ReasonText('   '))->toThrow(InvalidEnvelope::class, 'The free text of a reason is 1 to 4000 bytes of UTF-8 and not only white space.')
        ->and(static fn (): ReasonText => new ReasonText(str_repeat('x', 4001)))->toThrow(InvalidEnvelope::class, 'The free text of a reason')
        ->and(static fn (): ReasonText => new ReasonText("secret \xff"))->toThrow(InvalidEnvelope::class, 'The free text of a reason')
        ->and(new ReasonText(str_repeat('x', 4000))->classifiedContent())->toHaveLength(4000);

    $message = '';

    try {
        new ReasonText("Jane Doe \xff");
    } catch (InvalidEnvelope $error) {
        $message = $error->getMessage();
    }

    expect($message)->toStartWith('The free text of a reason')
        ->and(str_contains($message, 'Jane'))->toBeFalse();
});

it('holds provenance in order, with its parameters sorted and a model required', function (): void {
    $provenance = new Provenance(
        new GenerationModel('writer', '2026-08'),
        [new ModelParameter('top_p', '0.9'), new ModelParameter('temperature', '0.2')],
        new PromptReference('prompt:01936f5e'),
        [new SourceReference('https://b.example.test'), new SourceReference('https://a.example.test')],
    );

    expect(array_map(static fn (ModelParameter $parameter): string => $parameter->name, $provenance->parameters))->toBe(['temperature', 'top_p'])
        ->and(array_map(static fn (SourceReference $source): string => $source->value, $provenance->sources))->toBe(['https://b.example.test', 'https://a.example.test'])
        ->and($provenance->prompt?->value)->toBe('prompt:01936f5e')
        ->and($provenance->model?->version)->toBe('2026-08')
        ->and($provenance->isEmpty())->toBeFalse()
        ->and(new Provenance()->isEmpty())->toBeTrue()
        ->and(new Provenance(sources: [new SourceReference('feed:1')])->isEmpty())->toBeFalse()
        ->and(new Provenance(new GenerationModel('m', '1'))->isEmpty())->toBeFalse();

    expect(static fn (): Provenance => new Provenance(parameters: [new ModelParameter('t', '1')]))->toThrow(InvalidEnvelope::class, 'Provenance with model parameters or a prompt needs the model they belong to.')
        ->and(static fn (): Provenance => new Provenance(prompt: new PromptReference('p')))->toThrow(InvalidEnvelope::class, 'needs the model')
        ->and(static fn (): Provenance => new Provenance(new GenerationModel('m', '1'), [new ModelParameter('t', '1'), new ModelParameter('t', '2')]))->toThrow(InvalidEnvelope::class, 'The parameter "t" appears twice in one provenance.')
        ->and(static fn (): Provenance => new Provenance(sources: [new SourceReference('s'), new SourceReference('s')]))->toThrow(InvalidEnvelope::class, 'The source "s" appears twice in one provenance.');
});

it('refuses provenance text that is empty, too long or holds control characters', function (): void {
    expect(static fn (): GenerationModel => new GenerationModel('', '1'))->toThrow(InvalidEnvelope::class, 'A model name is UTF-8 text')
        ->and(static fn (): GenerationModel => new GenerationModel('m', "1\n"))->toThrow(InvalidEnvelope::class, 'A model version is UTF-8 text')
        ->and(static fn (): ModelParameter => new ModelParameter(str_repeat('n', 256), '1'))->toThrow(InvalidEnvelope::class, 'A parameter name')
        ->and(static fn (): ModelParameter => new ModelParameter('n', str_repeat('v', 256)))->toThrow(InvalidEnvelope::class, 'A parameter value')
        ->and(static fn (): GenerationModel => new GenerationModel(str_repeat('m', 256), '1'))->toThrow(InvalidEnvelope::class, 'A model name')
        ->and(static fn (): GenerationModel => new GenerationModel('m', str_repeat('v', 256)))->toThrow(InvalidEnvelope::class, 'A model version')
        ->and(new GenerationModel('m', str_repeat('v', 255))->version)->toHaveLength(255)
        ->and(static fn (): ModelParameter => new ModelParameter('n', "\xff"))->toThrow(InvalidEnvelope::class, 'A parameter value')
        ->and(static fn (): PromptReference => new PromptReference(str_repeat('p', 1025)))->toThrow(InvalidEnvelope::class, 'A prompt reference')
        ->and(static fn (): SourceReference => new SourceReference(str_repeat('s', 2049)))->toThrow(InvalidEnvelope::class, 'A source reference')
        ->and(new GenerationModel(str_repeat('m', 255), 'væ 1')->name)->toHaveLength(255)
        ->and(new ModelParameter(str_repeat('n', 255), str_repeat('v', 255))->value)->toHaveLength(255)
        ->and(new PromptReference(str_repeat('p', 1024))->value)->toHaveLength(1024)
        ->and(new SourceReference(str_repeat('s', 2048))->value)->toHaveLength(2048);
});

it('holds correlation ids and units of work as opaque visible ASCII', function (): void {
    expect(new CorrelationId(str_repeat('c', 128))->value)->toHaveLength(128)
        ->and(static fn (): CorrelationId => new CorrelationId(str_repeat('c', 129)))->toThrow(InvalidEnvelope::class, 'A correlation id is 1 to 128 visible ASCII characters')
        ->and(static fn (): CorrelationId => new CorrelationId(''))->toThrow(InvalidEnvelope::class, 'A correlation id')
        ->and(static fn (): CorrelationId => new CorrelationId('a b'))->toThrow(InvalidEnvelope::class, 'A correlation id')
        ->and(new CorrelationId('a')->equals(new CorrelationId('b')))->toBeFalse()
        ->and(new UnitOfWork(str_repeat('u', 255))->value)->toHaveLength(255)
        ->and(static fn (): UnitOfWork => new UnitOfWork(str_repeat('u', 256)))->toThrow(InvalidEnvelope::class, 'A unit of work is 1 to 255 visible ASCII characters')
        ->and(static fn (): UnitOfWork => new UnitOfWork("u\n"))->toThrow(InvalidEnvelope::class, 'A unit of work')
        ->and(new UnitOfWork('a')->equals(new UnitOfWork('b')))->toBeFalse();
});

it('tells the exposed surfaces from the internal issuers', function (): void {
    foreach (Surface::cases() as $surface) {
        expect(IssuingSurface::of($surface)->surface())->toBe($surface)
            ->and(IssuingSurface::of($surface)->isExternal())->toBeTrue();
    }

    foreach ([IssuingSurface::Job, IssuingSurface::Scheduler, IssuingSurface::Subscriber, IssuingSurface::Sidecar, IssuingSurface::Seed] as $issuer) {
        expect($issuer->surface())->toBeNull()
            ->and($issuer->isExternal())->toBeFalse();
    }

    expect(array_map(static fn (IssuerKind $kind): string => $kind->value, IssuerKind::cases()))
        ->toBe(['human', 'agent', 'seed', 'migration', 'scheduler', 'sync', 'system']);
});

/**
 * Every class the given namespaces' classes can hold, following property types.
 *
 * @param  list<string>  $namespaces
 * @return list<string>
 */
function envelopeReachableTypes(array $namespaces): array
{
    $root = dirname(__DIR__, 2).'/src';
    $queue = [];

    foreach ($namespaces as $namespace) {
        $directory = $root.'/'.str_replace('\\', '/', substr($namespace, strlen('Cbox\Cms\Contracts\\')));
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($root) + 1, -4);
                $queue[] = 'Cbox\Cms\Contracts\\'.str_replace('/', '\\', $relative);
            }
        }
    }

    $seen = [];

    while ($queue !== []) {
        $name = array_shift($queue);

        if (isset($seen[$name]) || (! class_exists($name) && ! interface_exists($name))) {
            continue;
        }

        $seen[$name] = true;

        foreach (new ReflectionClass($name)->getProperties() as $property) {
            $type = $property->getType();
            $named = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

            foreach ($named as $part) {
                if ($part instanceof ReflectionNamedType && ! $part->isBuiltin()) {
                    $queue[] = $part->getName();
                }
            }

            $doc = (string) $property->getDocComment();

            if (preg_match_all('/list<([A-Za-z\\\\|]+)>/', $doc, $matches) > 0) {
                foreach ($matches[1] as $list) {
                    foreach (explode('|', $list) as $short) {
                        $queue[] = str_contains($short, '\\') ? $short : $property->getDeclaringClass()->getNamespaceName().'\\'.$short;
                    }
                }
            }
        }
    }

    return array_keys($seen);
}
