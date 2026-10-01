<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * A credential on behalf of a person gets the intersection of its own grants and the person's
 * current grants (PRD 5.16, 2.31, 22), on Postgres as the app role over AccessWorld: AGENT holds
 * the role agent (ceiling sensitive) on the whole tree and a credential issued on behalf of ALICE,
 * who holds desk (internal) on NEWS less SPORT but FOOTBALL, and legal (personal) on CULTURE. As
 * ALICE's delegate AGENT reaches no node and reads no row ALICE does not, its classification is no
 * higher than hers, and it loses what she loses. The kernel reads only the grants of the people a
 * credential of the context's actor acts for.
 */

const DELEGATED_AGENT = '0192a0c0-0000-7000-8000-0000000000c7';

const DELEGATED_CREDENTIAL = '0192a0c0-0000-7000-8000-0000000000c8';

beforeEach(function (): void {
    AccessWorld::seed();
    AccessWorld::delegate(DELEGATED_AGENT, DELEGATED_CREDENTIAL, [AccessWorld::ALICE]);
    $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
    $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 43, clock: $clock));
    $fixtures->grant(ActorId::fromString(DELEGATED_AGENT), $fixtures->role('agent', ClassificationAccess::Sensitive), NodeId::fromString(AccessWorld::ROOT));
});

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * @param  list<string>  $onBehalfOf
 */
function delegatedAgent(array $onBehalfOf): ActorPrincipal
{
    return new ActorPrincipal(ActorId::fromString(DELEGATED_AGENT), array_map(ActorId::fromString(...), $onBehalfOf), IssuerKind::Service, ClassificationAccess::Sensitive);
}

/**
 * The context the resolver sets for the principal, the ids of the nodes its regions reach as row
 * level security tests them (cms_access_reaches; the app role also reads the node rows its own
 * grants name, which carry no content), and the ids of the entries the app role reads, sorted.
 *
 * @return array{AccessContext, list<string>, list<string>}
 */
function delegatedReads(ActorPrincipal $principal): array
{
    $app = DB::connection();
    $app->beginTransaction();

    try {
        $context = app(AccessResolver::class)->resolve($principal);
        $ids = static function (array $rows): array {
            $ids = array_map(static fn (mixed $row): string => is_object($row) && property_exists($row, 'id') && is_string($row->id) ? $row->id : '', $rows);
            sort($ids);

            return $ids;
        };

        return [
            $context,
            $ids($app->select('select id from nodes where cms_access_reaches(path)')),
            $ids($app->select('select id from entries')),
        ];
    } finally {
        $app->rollBack();
    }
}

it('reaches no node and reads no row beyond what the person reaches', function (): void {
    [, $agentNodes, $agentEntries] = delegatedReads(delegatedAgent([]));
    [$alice, $aliceNodes, $aliceEntries] = delegatedReads(AccessWorld::alice());
    [$delegated, $nodes, $entries] = delegatedReads(delegatedAgent([AccessWorld::ALICE]));

    expect($agentNodes)->toContain(AccessWorld::SPORT)
        ->and($agentEntries)->toContain(AccessWorld::ENTRY_SPORT)
        ->and($delegated->regions)->toEqual($alice->regions)
        ->and($nodes)->toBe($aliceNodes)
        ->and($nodes)->not->toContain(AccessWorld::SPORT)
        ->and($entries)->toBe($aliceEntries)
        ->and($entries)->not->toContain(AccessWorld::ENTRY_SPORT);
});

it('gets no classification above the person s', function (): void {
    [$agent] = delegatedReads(delegatedAgent([]));
    [$delegated] = delegatedReads(delegatedAgent([AccessWorld::ALICE]));

    // Alice's own access is internal: her personal role reaches only CULTURE, so it holds on no other node.
    expect($agent->classificationAccess)->toBe(ClassificationAccess::Sensitive)
        ->and($delegated->classificationAccess)->toBe(ClassificationAccess::Internal);
});

it('loses what the person loses', function (): void {
    StorageTables::superuser()->table('grants')->where('actor_id', AccessWorld::ALICE)->where('node_id', AccessWorld::CULTURE)->delete();

    [$delegated, $nodes] = delegatedReads(delegatedAgent([AccessWorld::ALICE]));

    expect($nodes)->not->toContain(AccessWorld::CULTURE)
        ->and($delegated->classificationAccess)->toBe(ClassificationAccess::Internal);

    StorageTables::superuser()->table('grants')->where('actor_id', AccessWorld::ALICE)->delete();

    [$emptied, $none] = delegatedReads(delegatedAgent([AccessWorld::ALICE]));

    expect($emptied->regions)->toBe([])
        ->and($none)->toBe([])
        ->and($emptied->classificationAccess)->toBe(ClassificationAccess::Public);
});

it('reads only the grants of a person a credential of the context s actor acts for', function (): void {
    [$undelegated, $nodes, $entries] = delegatedReads(delegatedAgent([AccessWorld::BOB]));

    expect($undelegated->regions)->toBe([])
        ->and($nodes)->toBe([])
        ->and($entries)->not->toContain(AccessWorld::ENTRY_SPORT)
        ->and($undelegated->classificationAccess)->toBe(ClassificationAccess::Public);

    $app = DB::connection();
    $app->beginTransaction();

    try {
        expect(fn (): array => $app->select('select * from cms_delegator_grants(?, null)', [AccessWorld::ALICE]))
            ->toThrow(QueryException::class, 'actor context');
    } finally {
        $app->rollBack();
    }
});
