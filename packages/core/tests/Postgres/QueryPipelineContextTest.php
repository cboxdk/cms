<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Identity\ActorClass;
use Cbox\Cms\Contracts\Identity\ActorPrincipal;
use Cbox\Cms\Contracts\Identity\ActorState;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\Identity\IssuerKind;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Pipeline\QueryCost;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Access\Domain\AccessResolver;
use Cbox\Cms\Core\Reads\Actions\QueryPipeline;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QuerySettings;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Reads\Domain\ReadableFields;
use Cbox\Cms\Core\Reads\Domain\ReadAudit;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Identity\PostgresIdentity;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Postgres\QueryProbe\SeeEntries;
use Cbox\Cms\Core\Tests\Postgres\QueryProbe\SeeEntriesAction;
use Cbox\Cms\Core\Tests\Postgres\QueryProbe\SeenContext;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryActions;
use Cbox\Cms\Core\Tests\Reads\Fakes\FakeQueryAuthorizer;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeQueryBinding;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\FixtureWriters\Access\Adapter\PostgresAccessFixtures;
use Cbox\Cms\Testkit\Identity\ServiceCredentialSpec;
use Cbox\Cms\Testkit\Ids\FakeIdGenerator;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/*
 * The query pipeline on one worker connection (PRD 5.10, 6.2, GUARDRAILS 2.1): reads as two actors,
 * one after the other on the same Postgres backend, each see only what their own grants reach, and
 * the second never sees the first's context: not in the policies, not in the settings between the
 * reads, and not after a read that broke halfway. The anonymous read after them sees only what is
 * public.
 *
 * Over the access world, NEWSDESK holds a role on NEWS, which reaches ENTRY_NEWS, ENTRY_SPORT and
 * ENTRY_FOOTBALL, and CULTUREDESK a role on CULTURE, which reaches ENTRY_CULTURE; every context
 * also reads ENTRY_PUBLIC, released with a live placement. Both are service actors with credentials
 * the Postgres verifier checks.
 */

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
});

/**
 * @return array{QueryPipeline, SeenContext, TransportCredential, TransportCredential, PostgresIdentity}
 */
function workerWorld(): array
{
    AccessWorld::seed();
    $clock = new FakeClock(new DateTimeImmutable('2026-03-10T12:00:00Z'));
    $identity = PostgresIdentity::at($clock);
    $fixtures = new PostgresAccessFixtures(app(DatabaseManager::class), $clock, new FakeIdGenerator(seed: 2626, clock: $clock));
    $reader = $fixtures->role('reader', ClassificationAccess::Internal);
    $credentials = [];

    foreach ([AccessWorld::NEWS, AccessWorld::CULTURE] as $node) {
        $actor = $identity->addActor(ActorClass::Service)->id;
        $fixtures->grant($actor, $reader, NodeId::fromString($node));
        $credentials[] = $identity->issue(new ServiceCredentialSpec($actor, IssuerKind::Service, ClassificationAccess::Internal, $clock->now()->add(new DateInterval('P1D'))));
    }

    $seen = new SeenContext;
    $pipeline = new QueryPipeline(
        new FakeQueryActions([SeeEntries::class => ProbeQueryBinding::of(new SeeEntriesAction(app(DatabaseManager::class), $seen), 'probe.see_entries', 1)]),
        $identity->verifier(),
        app(AccessResolver::class),
        new FakeQueryAuthorizer,
        new QuerySettings(new QueryCost(10), new QueryCost(10)),
        new ReadableFields(new FakeTypeCatalog),
        app(ReadAudit::class),
        app(QueryTransaction::class),
        new PipelineTelemetry(new FakeTelemetry, new FakeClock, new FakeStopwatch),
    );

    return [$pipeline, $seen, $credentials[0], $credentials[1], $identity];
}

/**
 * The context settings of the default connection outside any read, as the policies read them.
 */
function contextBetweenReads(): string
{
    $connection = DB::connection();

    expect($connection->transactionLevel())->toBe(0);

    return StorageTables::texts($connection, <<<'SQL'
        select concat_ws(' | ',
            coalesce(cms_access_context(), '-'),
            coalesce(cms_access_actor()::text, '-'),
            coalesce(nullif(current_setting('cbox_cms.access_allowed', true), ''), '-'),
            coalesce(nullif(current_setting('cbox_cms.classification', true), ''), '-')
        ) as value
        SQL)[0];
}

it('runs two reads as two actors on one worker connection, and the second never sees the first\'s context', function (): void {
    [$pipeline, $seen, $newsdesk, $culturedesk] = workerWorld();

    $first = $pipeline->run(new QueryCall(new SeeEntries, $newsdesk));
    $between = contextBetweenReads();
    $second = $pipeline->run(new QueryCall(new SeeEntries, $culturedesk));
    $after = contextBetweenReads();
    $anonymous = $pipeline->run(new QueryCall(new SeeEntries, null));

    expect(array_map(static fn (QueryResult $result): bool => $result->isAnswered(), [$first, $second, $anonymous]))->toBe([true, true, true])
        ->and(array_unique(array_column($seen->reads, 'pid')))->toHaveCount(1)
        ->and(array_column($seen->reads, 'principal'))->toBe(['actor', 'actor', 'anonymous'])
        ->and($seen->reads[0]['actor'])->not->toBe($seen->reads[1]['actor'])
        ->and($seen->reads[2]['actor'])->toBe('-')
        ->and($seen->reads[0]['entries'])->toBe([AccessWorld::ENTRY_NEWS, AccessWorld::ENTRY_SPORT, AccessWorld::ENTRY_FOOTBALL, AccessWorld::ENTRY_PUBLIC])
        ->and($seen->reads[1]['entries'])->toBe([AccessWorld::ENTRY_CULTURE, AccessWorld::ENTRY_PUBLIC])
        ->and($seen->reads[2]['entries'])->toBe([AccessWorld::ENTRY_PUBLIC])
        ->and($between)->toBe('- | - | - | -')
        ->and($after)->toBe('- | - | - | -');

    expect(array_map(static fn (DependencyKey $key): string => $key->toString(), $second->contentKeys))->toBe([
        'e-'.AccessWorld::ENTRY_CULTURE,
        'e-'.AccessWorld::ENTRY_PUBLIC,
        'n-'.AccessWorld::ROOT,
        'n-'.AccessWorld::CULTURE,
    ])
        ->and($second->position)->not->toBeNull();
});

it('leaves no context behind a read that broke halfway, for the next read on the connection', function (): void {
    [$pipeline, $seen, $newsdesk, $culturedesk] = workerWorld();

    expect(fn (): QueryResult => $pipeline->run(new QueryCall(new SeeEntries(fail: true), $newsdesk)))->toThrow(RuntimeException::class, 'The probe read broke halfway.');

    $between = contextBetweenReads();
    $next = $pipeline->run(new QueryCall(new SeeEntries, $culturedesk));

    expect($between)->toBe('- | - | - | -')
        ->and($next->isAnswered())->toBeTrue()
        ->and($seen->reads[0]['pid'])->toBe($seen->reads[1]['pid'])
        ->and($seen->reads[0]['entries'])->toContain(AccessWorld::ENTRY_NEWS)
        ->and($seen->reads[1]['entries'])->toBe([AccessWorld::ENTRY_CULTURE, AccessWorld::ENTRY_PUBLIC]);
});

it('rejects a read whose actor was deactivated since the last read on the connection, and reads nothing for it', function (): void {
    [$pipeline, $seen, $newsdesk, , $identity] = workerWorld();

    expect($pipeline->run(new QueryCall(new SeeEntries, $newsdesk))->isAnswered())->toBeTrue();

    $actor = $identity->verifier()->verify($newsdesk);
    $identity->changeState($actor instanceof ActorPrincipal ? $actor->actor : throw new RuntimeException('Expected an actor.'), ActorState::Deactivated);
    $refused = $pipeline->run(new QueryCall(new SeeEntries, $newsdesk));

    expect($refused->isAnswered())->toBeFalse()
        ->and(array_map(static fn (CatalogError $error): string => $error->code->value, $refused->errors))->toBe(['actor_not_active'])
        ->and($seen->reads)->toHaveCount(1)
        ->and(contextBetweenReads())->toBe('- | - | - | -');
});
