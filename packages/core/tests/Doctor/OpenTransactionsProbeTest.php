<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Doctor;

use Cbox\Cms\Core\Doctor\Adapter\ConnectionPostgresProbe;
use Cbox\Cms\Core\Doctor\Adapter\DoctorConnection;
use Cbox\Cms\Core\Doctor\Domain\Dto\HeldTransaction;
use Illuminate\Database\DatabaseManager;

/*
 * The probe's reading of the sessions that hold the horizons, apart from Postgres: the oldest
 * measured session that holds a transaction id and the oldest that holds a snapshot, the first
 * of two equally old ones, an age below zero read as zero, and the roles of the unmeasured
 * sessions, each once and sorted, with a session without a role named unknown.
 */

const OPEN_TRANSACTIONS_CONNECTION = 'cms_doctor_rows';

/**
 * A session row as the probe's query gives it.
 */
function heldSession(int $pid, string $role, bool $xid, bool $snapshot, bool $measured, int $milliseconds): object
{
    return (object) ['pid' => $pid, 'role' => $role, 'holds_xid' => $xid, 'holds_snapshot' => $snapshot, 'measured' => $measured, 'milliseconds' => $milliseconds];
}

/**
 * The probe on a connection whose every select answers with $rows.
 */
function probeAnswering(object ...$rows): ConnectionPostgresProbe
{
    $databases = app(DatabaseManager::class);
    $databases->extend(OPEN_TRANSACTIONS_CONNECTION, static fn (): RowsConnection => new RowsConnection(array_values($rows)));
    config()->set('database.connections.cms_doctor_rows_source', ['driver' => 'pgsql']);

    return new ConnectionPostgresProbe(new DoctorConnection($databases, app('config'), 'cms_doctor_rows_source', OPEN_TRANSACTIONS_CONNECTION, 1));
}

afterEach(function (): void {
    app(DatabaseManager::class)->purge(OPEN_TRANSACTIONS_CONNECTION);
});

it('gives the oldest session that holds a transaction id and the oldest that holds a snapshot, the first of two as old', function (): void {
    $open = probeAnswering(
        heldSession(11, 'cms_app', true, false, true, 100),
        heldSession(12, 'cms_app', true, true, true, 300),
        heldSession(13, 'cms_app', true, false, true, 300),
        heldSession(14, 'cms_app', false, true, true, 400),
        heldSession(15, 'cms_app', false, true, true, 400),
    )->openTransactions();

    expect($open->oldestXid)->toEqual(new HeldTransaction(12, 'cms_app', 300))
        ->and($open->oldestSnapshot)->toEqual(new HeldTransaction(14, 'cms_app', 400))
        ->and([$open->unmeasured, $open->unmeasuredRoles])->toBe([0, []]);
});

it('reads an age below zero as zero, and counts and names the unmeasured sessions by role, each role once', function (): void {
    $open = probeAnswering(
        heldSession(21, 'cms_app', true, true, true, -5),
        heldSession(22, 'cms_owner', true, false, false, 0),
        heldSession(23, '', false, true, false, 0),
        heldSession(24, 'cms_owner', false, true, false, 0),
    )->openTransactions();

    expect($open->oldestXid)->toEqual(new HeldTransaction(21, 'cms_app', 0))
        ->and($open->oldestSnapshot)->toEqual(new HeldTransaction(21, 'cms_app', 0))
        ->and($open->unmeasured)->toBe(3)
        ->and($open->unmeasuredRoles)->toBe(['cms_owner', 'unknown']);
});

it('gives no oldest session when none is measured', function (): void {
    $open = probeAnswering(heldSession(31, 'cms_owner', true, true, false, 0))->openTransactions();

    expect([$open->oldestXid, $open->oldestSnapshot, $open->unmeasured])->toBe([null, null, 1]);
});
