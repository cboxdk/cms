<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Core\Partitions\Actions\MaintainPartitions;
use Cbox\Cms\Core\Partitions\Domain\DdlStep;
use Cbox\Cms\Core\Partitions\Domain\Dto\PartitionRange;
use Cbox\Cms\Core\Partitions\Domain\InvalidPartitionPolicy;
use Cbox\Cms\Core\Partitions\Domain\LockTimeout;
use Cbox\Cms\Core\Partitions\Domain\OwnerConnectionRequired;
use Cbox\Cms\Core\Partitions\Domain\PartitionChangeKind;
use Cbox\Cms\Core\Partitions\Domain\PartitionedTable;
use Cbox\Cms\Core\Partitions\Domain\PartitionInterval;
use Cbox\Cms\Core\Partitions\Domain\PartitionKey;
use Cbox\Cms\Core\Partitions\Domain\PartitionPolicy;
use Cbox\Cms\Core\Tests\Partitions\Fakes\FakePartitionMaintenance;
use Cbox\Cms\Testkit\Clock\FakeClock;
use DateTimeImmutable;

/*
 * The partition action called directly with its DTO, the fake manager and the FakeClock
 * (GUARDRAILS 9). FakePartitionMaintenance is held to PostgresPartitionManager by
 * PartitionMaintenanceBehaviour.
 */

function fakePartitions(?int $retentionDays = null): FakePartitionMaintenance
{
    return new FakePartitionMaintenance(new PartitionPolicy(
        'pgsql_owner',
        [new PartitionedTable('events', PartitionKey::Uuid7, PartitionInterval::Day, $retentionDays)],
        runwayDays: 2,
    ));
}

it('maintains the partitions at the Clock\'s time', function (): void {
    $partitions = fakePartitions(retentionDays: 1);
    $clock = new FakeClock(new DateTimeImmutable('2026-05-01T10:00:00Z'));
    $action = new MaintainPartitions($partitions, $clock);

    expect($action->maintain()->partitions(PartitionChangeKind::Created))->toBe(['events_p20260501', 'events_p20260502', 'events_p20260503']);

    $clock->set(new DateTimeImmutable('2026-05-04T00:00:00Z'));
    $report = $action->maintain();

    expect($report->partitions(PartitionChangeKind::Created))->toBe(['events_p20260504', 'events_p20260505', 'events_p20260506'])
        ->and($report->partitions(PartitionChangeKind::Dropped))->toBe(['events_p20260501', 'events_p20260502'])
        ->and($partitions->partitions('events'))->toBe(['events_p20260503', 'events_p20260504', 'events_p20260505', 'events_p20260506']);
});

it('covers the range it is given, whatever the Clock shows, removes nothing, and reports the runway from the Clock', function (): void {
    $partitions = fakePartitions(retentionDays: 1);
    $clock = new FakeClock(new DateTimeImmutable('2031-01-01T00:00:00Z'));
    $action = new MaintainPartitions($partitions, $clock);

    $report = $action->cover(new PartitionRange(new DateTimeImmutable('2024-02-28T23:00:00Z'), new DateTimeImmutable('2024-03-01T00:00:00Z')));

    expect($report->partitions(PartitionChangeKind::Created))->toBe(['events_p20240228', 'events_p20240229', 'events_p20240301'])
        ->and($report->partitions(PartitionChangeKind::Dropped))->toBe([])
        ->and($report->runways[0]->coveredUntil)->toBeNull();

    $clock->set(new DateTimeImmutable('2024-02-29T08:00:00Z'));

    expect($action->cover(new PartitionRange(new DateTimeImmutable('2024-03-03T00:00:00Z'), new DateTimeImmutable('2024-03-03T00:00:00Z')))->runways[0]->coveredUntil?->format(DATE_ATOM))
        ->toBe('2024-03-02T00:00:00+00:00');
});

it('passes a busy lock on as LockTimeout, and the next run after it is released does the work', function (): void {
    $partitions = fakePartitions();
    $action = new MaintainPartitions($partitions, new FakeClock(new DateTimeImmutable('2026-05-01T10:00:00Z')));
    $partitions->holdRunLock();

    expect(static fn (): mixed => $action->maintain())->toThrow(LockTimeout::class, 'Gave up on step "'.DdlStep::Lock->value.'"')
        ->and($partitions->partitions('events'))->toBe([]);

    $partitions->releaseRunLock();

    expect($action->maintain()->changes)->toHaveCount(3);
});

it('reports a LockTimeout on a table another session holds, still maintains the other tables, and finishes on the next run', function (): void {
    $partitions = new FakePartitionMaintenance(new PartitionPolicy('pgsql_owner', [
        new PartitionedTable('audit', PartitionKey::Timestamp, PartitionInterval::Day, null),
        new PartitionedTable('events', PartitionKey::Uuid7, PartitionInterval::Day, null),
    ], runwayDays: 1, lockTimeoutMs: 1500, attempts: 2));
    $action = new MaintainPartitions($partitions, new FakeClock(new DateTimeImmutable('2026-05-01T10:00:00Z')));
    $partitions->lockTable('audit');

    $report = $action->maintain();
    $timeout = $report->gaveUp[0] ?? null;

    expect($report->gaveUp)->toHaveCount(1)
        ->and($report->isComplete())->toBeFalse()
        ->and($timeout?->step)->toBe(DdlStep::Create)
        ->and($timeout?->table)->toBe('audit')
        ->and($timeout?->partition)->toBe('audit_p20260501')
        ->and($timeout?->attempts)->toBe(2)
        ->and($timeout?->message)->toStartWith('['.LockTimeout::CODE.'] Gave up on step "create" for partition "audit_p20260501" of table "audit" after 2 attempts')
        ->toContain('lock_timeout 1500ms')
        ->and($report->partitions(PartitionChangeKind::Created))->toBe(['events_p20260501', 'events_p20260502'])
        ->and($partitions->partitions('events'))->toBe(['events_p20260501', 'events_p20260502'])
        ->and($partitions->partitions('audit'))->toBe([]);

    $partitions->unlockTable('audit');
    $next = $action->maintain();

    expect($next->isComplete())->toBeTrue()
        ->and($next->partitions(PartitionChangeKind::Created))->toBe(['audit_p20260501', 'audit_p20260502'])
        ->and($partitions->partitions('audit'))->toBe(['audit_p20260501', 'audit_p20260502']);
});

it('creates the runway of every table before it retires any, and a busy table keeps its partitions while the others are maintained', function (): void {
    $partitions = new FakePartitionMaintenance(new PartitionPolicy('pgsql_owner', [
        new PartitionedTable('events', PartitionKey::Uuid7, PartitionInterval::Day, 1),
        new PartitionedTable('audit', PartitionKey::Timestamp, PartitionInterval::Day, 1),
    ], runwayDays: 2));
    $clock = new FakeClock(new DateTimeImmutable('2026-05-01T10:00:00Z'));
    $action = new MaintainPartitions($partitions, $clock);
    $action->maintain();
    $partitions->lockTable('events');
    $clock->set(new DateTimeImmutable('2026-05-03T00:00:00Z'));

    $report = $action->maintain();

    // The lock keeps both phases from the events table: the create gives up first, because every
    // table is created before any is retired, and the audit table is maintained in full.
    expect($report->gaveUp)->toHaveCount(2)
        ->and($report->gaveUp[0]->message)->toContain('Gave up on step "'.DdlStep::Create->value.'" for partition "events_p20260504" of table "events"')
        ->and($report->gaveUp[1]->message)->toContain('Gave up on step "'.DdlStep::Detach->value.'" for partition "events_p20260501" of table "events"')
        ->and($report->partitions(PartitionChangeKind::Created))->toBe(['audit_p20260504', 'audit_p20260505'])
        ->and($report->partitions(PartitionChangeKind::Dropped))->toBe(['audit_p20260501'])
        ->and($partitions->partitions('events'))->toBe(['events_p20260501', 'events_p20260502', 'events_p20260503'])
        ->and($partitions->partitions('audit'))->toBe(['audit_p20260502', 'audit_p20260503', 'audit_p20260504', 'audit_p20260505']);

    $partitions->unlockTable('events');
    $next = $action->maintain();

    expect($next->isComplete())->toBeTrue()
        ->and($next->partitions(PartitionChangeKind::Created))->toBe(['events_p20260504', 'events_p20260505'])
        ->and($next->partitions(PartitionChangeKind::Dropped))->toBe(['events_p20260501'])
        ->and($partitions->partitions('events'))->toBe(['events_p20260502', 'events_p20260503', 'events_p20260504', 'events_p20260505']);
});

it('refuses to run on the application\'s connection and changes nothing', function (): void {
    $partitions = new FakePartitionMaintenance(new PartitionPolicy('pgsql', [
        new PartitionedTable('events', PartitionKey::Uuid7, PartitionInterval::Day, null),
    ], runwayDays: 2));
    $action = new MaintainPartitions($partitions, new FakeClock(new DateTimeImmutable('2026-05-01T10:00:00Z')));

    expect(static fn (): mixed => $action->maintain())->toThrow(OwnerConnectionRequired::class, 'the connection [pgsql], which is the application\'s default connection')
        ->and(static fn (): mixed => $action->cover(new PartitionRange(new DateTimeImmutable('2026-05-01T00:00:00Z'), new DateTimeImmutable('2026-05-02T00:00:00Z'))))
        ->toThrow(OwnerConnectionRequired::class)
        ->and($partitions->partitions('events'))->toBe([]);
});

it('passes on a range that needs too many partitions', function (): void {
    $action = new MaintainPartitions(fakePartitions(), new FakeClock(new DateTimeImmutable('2026-05-01T10:00:00Z')));

    expect(static fn (): mixed => $action->cover(new PartitionRange(new DateTimeImmutable('2020-01-01T00:00:00Z'), new DateTimeImmutable('2030-01-01T00:00:00Z'))))
        ->toThrow(InvalidPartitionPolicy::class, 'needs more than '.PartitionedTable::MAX_PARTITIONS_PER_CALL.' partitions');
});

it('takes a range only from its start to its end', function (): void {
    expect(static fn (): PartitionRange => new PartitionRange(new DateTimeImmutable('2026-01-02T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:00:00Z')))
        ->toThrow(InvalidPartitionPolicy::class, 'The range ends at 2026-01-01T00:00:00+00:00, before it starts at 2026-01-02T00:00:00+00:00.')
        ->and(new PartitionRange(new DateTimeImmutable('2026-01-01T00:00:00Z'), new DateTimeImmutable('2026-01-01T00:00:00Z'))->to->format(DATE_ATOM))
        ->toBe('2026-01-01T00:00:00+00:00');
});
