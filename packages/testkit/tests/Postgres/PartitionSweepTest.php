<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\Infrastructure\PartitionSweep;
use Illuminate\Support\Facades\DB;

/*
 * The sweep before migrate:fresh: every leaf partition in the search path goes, one statement
 * each, and the partitioned tables stay for migrate:fresh. It runs here in a scratch schema of its
 * own, so the checkout's partitions stay.
 */

it('drops every leaf partition in the search path, and leaves the partitioned tables and other tables', function (): void {
    $owner = DB::connection('pgsql_owner');
    $path = $owner->scalar('show search_path');
    $owner->statement('create schema sweep_probe');

    try {
        $owner->statement('set search_path = sweep_probe');
        $owner->statement('create table days (day int not null) partition by range (day)');
        $owner->statement('create table days_p1 partition of days for values from (1) to (2)');
        $owner->statement('create table days_p2 partition of days for values from (2) to (3)');
        $owner->statement('create table kinds (kind text not null, id int not null) partition by list (kind)');
        $owner->statement("create table kinds_a partition of kinds for values in ('a') partition by range (id)");
        $owner->statement('create table kinds_a_p0 partition of kinds_a for values from (0) to (10)');
        $owner->statement('create table plain (id int)');

        $dropped = new PartitionSweep($owner)->drop();
        $left = $owner->select("select relname::text as name from pg_class where relnamespace = 'sweep_probe'::regnamespace and relkind in ('r', 'p') order by 1");
        $again = new PartitionSweep($owner)->drop();
    } finally {
        $owner->statement('set search_path = '.(is_string($path) ? $path : 'public'));
        $owner->statement('drop schema sweep_probe cascade');
    }

    expect($dropped)->toBe(['sweep_probe.days_p1', 'sweep_probe.days_p2', 'sweep_probe.kinds_a_p0'])
        ->and(array_map(static fn (mixed $row): mixed => is_object($row) ? ($row->name ?? null) : null, $left))->toBe(['days', 'kinds', 'kinds_a', 'plain'])
        ->and($again)->toBe([]);
});
