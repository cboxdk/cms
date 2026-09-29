<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\AnonymousPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Core\Reads\Domain\Dto\AuditedRead;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\InvalidQueryCall;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCards;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCount;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Cbox\Cms\Core\Tests\Reads\QueryWorld;
use Cbox\Cms\Testkit\Identity\FakeIdentity;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use DateInterval;

/*
 * The query pipeline (GUARDRAILS 2.1, PRD 6.2 "Læsninger") called directly with the test-only query
 * probe.read and the fakes of its ports and of the contracts it reads (GUARDRAILS 9): the identity,
 * the access resolver, the authorizer, the type catalog, the read audit and the read transaction.
 * It covers the answer, the rejections of a credential, of an actor that is not active, of the
 * authorizer and of the budget, the stripping of fields, the read audit and the content keys.
 */

/**
 * @return list<string> each error as its code
 */
function queryErrors(QueryResult $result): array
{
    return array_map(static fn (CatalogError $error): string => $error->code->value, $result->errors);
}

/**
 * The addresses of the fields of an entry, the owner's first, then each extender's.
 *
 * @return list<string>
 */
function fieldAddresses(FieldValues $fields): array
{
    return [
        ...array_map(static fn (FieldHandle $handle): string => $handle->value, $fields->own->handles()),
        ...array_merge(...array_map(
            static fn (ExtensionFields $extension): array => array_map(
                static fn (FieldHandle $handle): string => 'ext.'.$extension->namespace->value.'.'.$handle->value,
                $extension->fields->handles(),
            ),
            $fields->extensions,
        )),
    ];
}

/**
 * @return list<list<string>> the field addresses of each card of the answer
 */
function answeredFields(QueryResult $result): array
{
    expect($result->result)->toBeInstanceOf(ProbeCards::class);

    return $result->result instanceof ProbeCards
        ? array_map(static fn (ReadContent $card): array => fieldAddresses($card->fields), $result->result->cards)
        : [];
}

/**
 * @return list<string>
 */
function keysOf(QueryResult $result): array
{
    return array_map(static fn (DependencyKey $key): string => $key->toString(), $result->contentKeys);
}

it('answers a read as the credential\'s actor with the stripped result, its content keys and its position, and commits', function (): void {
    $world = new QueryWorld;

    $result = $world->read(2);

    expect($result->isAnswered())->toBeTrue()
        ->and($result->errors)->toBe([])
        ->and(answeredFields($result))->toBe([
            ['label', 'memo', 'note', 'ext.probe.tag'],
            ['label', 'memo', 'note', 'ext.probe.tag'],
        ])
        ->and(keysOf($result))->toBe([
            'e-'.QueryWorld::ENTRIES[0],
            'e-'.QueryWorld::ENTRIES[1],
            'n-'.QueryWorld::NODE,
        ])
        ->and($result->position?->value)->toBe(QueryWorld::POSITION)
        ->and($world->library->handled)->toHaveCount(1)
        ->and($world->transaction->commits)->toBe(1)
        ->and($world->transaction->rollBacks)->toBe(0)
        ->and($world->audit->records)->toBe([]);

    $asked = $world->authorizer->asked;
    $principal = $asked[0][0]->principal;

    expect($asked)->toHaveCount(1)
        ->and($asked[0][1]->value)->toBe('probe.read')
        ->and($asked[0][0])->toBe($world->access->resolved[0])
        ->and($principal)->toBeInstanceOf(ActorPrincipal::class)
        ->and($principal instanceof ActorPrincipal && $principal->actor->equals($world->reader))->toBeTrue()
        ->and($asked[0][0]->classificationAccess)->toBe(ClassificationAccess::Confidential);
});

it('reads as the anonymous principal without a credential, and strips every field above public', function (): void {
    $world = new QueryWorld;

    $result = $world->read(1, anonymous: true);

    expect($result->isAnswered())->toBeTrue()
        ->and(answeredFields($result))->toBe([['label', 'ext.probe.tag']])
        ->and($world->access->resolved)->toHaveCount(1)
        ->and($world->access->resolved[0]->principal)->toBeInstanceOf(AnonymousPrincipal::class)
        ->and($world->access->resolved[0]->classificationAccess)->toBe(ClassificationAccess::Public);
});

it('keeps sensitive fields for an actor allowed them, and writes their read audit with the read in one record, each entry once', function (): void {
    $world = new QueryWorld;
    $world->access->grant($world->reader, [], ClassificationAccess::Sensitive);
    $world->library->cards = array_map(QueryWorld::card(...), [QueryWorld::ENTRIES[0], QueryWorld::ENTRIES[1], QueryWorld::ENTRIES[0]]);

    $result = $world->read(3);

    expect(answeredFields($result)[0])->toBe(['diagnosis', 'label', 'memo', 'note', 'ext.probe.code', 'ext.probe.tag'])
        ->and($world->audit->records)->toHaveCount(1);

    $record = $world->audit->records[0];

    expect($record->actor->equals($world->reader))->toBeTrue()
        ->and($record->query->value)->toBe('probe.read')
        ->and($record->version)->toBe(2)
        ->and($record->position->value)->toBe(QueryWorld::POSITION)
        ->and(array_map(static fn (AuditedRead $read): array => [$read->entry->toString(), $read->fields, $read->classification], $record->reads))->toBe([
            [QueryWorld::ENTRIES[0], ['diagnosis', 'ext.probe.code'], ClassificationAccess::Sensitive],
            [QueryWorld::ENTRIES[1], ['diagnosis', 'ext.probe.code'], ClassificationAccess::Sensitive],
        ]);
});

it('caps what an actor reads at its credential\'s ceiling, whatever its grants allow', function (): void {
    $world = new QueryWorld;
    $person = $world->identity->addActor(ActorClass::Service)->id;
    $credential = $world->identity->issue(new ServiceCredentialSpec($person, IssuerKind::Agent, ClassificationAccess::Internal, $world->clock->now()->add(new DateInterval('P1D'))));
    $world->access->grant($person, [], ClassificationAccess::Sensitive);

    $result = $world->pipeline()->run(new QueryCall(new ReadProbe, $credential));

    expect(answeredFields($result))->toBe([['label', 'note', 'ext.probe.tag']])
        ->and($world->audit->records)->toBe([]);
});

it('rejects a credential of an actor that is not active before it sets a context or reads', function (ActorState $state): void {
    $world = new QueryWorld;
    $world->identity->changeState($world->reader, $state);

    $result = $world->read();

    expect($result->isAnswered())->toBeFalse()
        ->and(queryErrors($result))->toBe(['actor_not_active'])
        ->and($result->result)->toBeNull()
        ->and($result->contentKeys)->toBe([])
        ->and($result->position)->toBeNull()
        ->and($world->access->resolved)->toBe([])
        ->and($world->authorizer->asked)->toBe([])
        ->and($world->library->handled)->toBe([])
        ->and($world->transaction->rollBacks)->toBe(1)
        ->and($world->transaction->commits)->toBe(0);
})->with([ActorState::Deactivated, ActorState::Deprovisioned, ActorState::Pending]);

it('rejects a credential that acts on behalf of an actor that is not active', function (): void {
    $world = new QueryWorld;
    $person = $world->identity->addActor(ActorClass::Staff)->id;
    $agent = $world->identity->addActor(ActorClass::Service)->id;
    $credential = $world->identity->issue(new ServiceCredentialSpec($agent, IssuerKind::Agent, ClassificationAccess::Internal, $world->clock->now()->add(new DateInterval('P1D')), [$person]));
    $world->identity->changeState($person, ActorState::Deactivated);

    $result = $world->pipeline()->run(new QueryCall(new ReadProbe, $credential));

    expect(queryErrors($result))->toBe(['actor_not_active'])
        ->and($world->library->handled)->toBe([]);
});

it('rejects a credential that does not verify with the code of its reason, and never reads as anonymous instead', function (): void {
    $world = new QueryWorld;
    $call = static fn (TransportCredential $credential): QueryResult => $world->pipeline()->run(new QueryCall(new ReadProbe, $credential));

    $elsewhere = new FakeIdentity($world->clock);
    $stranger = $elsewhere->addActor(ActorClass::Service)->id;
    $unknown = $call($elsewhere->issue(new ServiceCredentialSpec($stranger, IssuerKind::Service, ClassificationAccess::Public, $world->clock->now()->add(new DateInterval('P1D')))));
    $malformed = $call(new TransportCredential('not-a-credential'));
    $world->clock->advance(new DateInterval('P2D'));
    $expired = $call($world->credential);

    expect(queryErrors($malformed))->toBe(['credential_malformed'])
        ->and(queryErrors($unknown))->toBe(['credential_unknown'])
        ->and(queryErrors($expired))->toBe(['credential_expired'])
        ->and($expired->errors[0]->message)->toBe('The credential was refused: it has expired.')
        ->and($world->access->resolved)->toBe([])
        ->and($world->library->handled)->toBe([]);
});

it('rejects a read the authorizer refuses, with its reason, after the context is set and before the action runs', function (): void {
    $world = new QueryWorld()->refuse('The reader holds no role that may read cards.');

    $result = $world->read();

    expect(queryErrors($result))->toBe(['unauthorized'])
        ->and($result->errors[0]->message)->toBe('The reader holds no role that may read cards.')
        ->and($world->access->resolved)->toHaveCount(1)
        ->and($world->library->handled)->toBe([])
        ->and($world->transaction->rollBacks)->toBe(1);
});

it('rejects a read that costs more than its principal\'s budget before the action runs, and answers one at the budget', function (): void {
    $world = new QueryWorld;

    $actorOver = $world->read(QueryWorld::ACTOR_BUDGET + 1);
    $anonymousOver = $world->read(QueryWorld::ANONYMOUS_BUDGET + 1, anonymous: true);

    expect(queryErrors($actorOver))->toBe(['query_over_budget'])
        ->and($actorOver->errors[0]->message)->toBe('The read probe.read costs 4, above the budget of 3 for an actor.')
        ->and(queryErrors($anonymousOver))->toBe(['query_over_budget'])
        ->and($anonymousOver->errors[0]->message)->toBe('The read probe.read costs 3, above the budget of 2 for the anonymous principal.')
        ->and($world->library->handled)->toBe([])
        ->and($world->authorizer->asked)->toHaveCount(2);

    expect($world->read(QueryWorld::ACTOR_BUDGET)->isAnswered())->toBeTrue()
        ->and($world->read(QueryWorld::ANONYMOUS_BUDGET, anonymous: true)->isAnswered())->toBeTrue();
});

it('answers a result without content as it is, with no content keys and no read audit', function (): void {
    $world = new QueryWorld;
    $world->access->grant($world->reader, [], ClassificationAccess::Sensitive);
    $world->library->answer = 'count';

    $result = $world->read();

    expect($result->result)->toEqual(new ProbeCount(3))
        ->and($result->contentKeys)->toBe([])
        ->and($result->position?->value)->toBe(QueryWorld::POSITION)
        ->and($world->audit->records)->toBe([]);
});

it('refuses a result that keeps the fields the pipeline stripped, and rolls the read back', function (): void {
    $world = new QueryWorld;
    $world->access->grant($world->reader, [], ClassificationAccess::Sensitive);
    $world->library->answer = 'leaky';

    expect(fn (): QueryResult => $world->read(anonymous: true))->toThrow(InvalidQueryCall::class, 'LeakyCards holds other entries or fields after withContents()')
        ->and($world->transaction->rollBacks)->toBe(1)
        ->and($world->transaction->commits)->toBe(0)
        ->and($world->audit->records)->toBe([]);
});

it('leaves no context behind: each read sets its own, and none is in effect between reads', function (): void {
    $world = new QueryWorld;

    $world->read();
    $between = $world->access->current();
    $world->read(anonymous: true);

    expect($between)->toBeNull()
        ->and($world->access->current())->toBeNull()
        ->and(array_map(static fn (AccessContext $context): string => $context->principal::class, $world->access->resolved))->toBe([ActorPrincipal::class, AnonymousPrincipal::class])
        ->and($world->access->resolved[1]->classificationAccess)->toBe(ClassificationAccess::Public)
        ->and($world->transaction->commits)->toBe(2);
});

it('refuses a query that no query action handles before it begins a transaction', function (): void {
    $world = new QueryWorld;
    $stray = new readonly class implements Query {};

    expect(fn (): QueryResult => $world->pipeline()->run(new QueryCall($stray, null)))->toThrow(UnknownQuery::class, 'No query action handles the query '.$stray::class)
        ->and($world->transaction->commits + $world->transaction->rollBacks)->toBe(0);
});
