<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\Boundary\CheckoutRoot;
use Cbox\Cms\Tests\Support\Tooling\DropDatabaseScripts;
use Illuminate\Support\Facades\DB;

/*
 * composer scale:check end to end on the shared server (tools/bin/scale-check.php, GUARDRAILS 4.3,
 * MILESTONES M1), with a few hundred entries in a scale database of this checkout's own: it creates
 * and migrates the database, writes the site and the service actor, seeds through
 * `vendor/bin/testbench cms:seed-scale`, runs ANALYZE and the workbench listing with EXPLAIN
 * (ANALYZE), prints the machine, the seed's wall time and the median of each page, and exits 0
 * under the budget. The database is dropped afterwards.
 */

it('seeds a scale database of its own and prints the median of the listing under the budget', function (): void {
    $database = 'cms_scale_'.substr(hash('sha256', CheckoutRoot::current()), 0, 12);
    $process = DropDatabaseScripts::process([PHP_BINARY, 'tools/bin/scale-check.php', '--entries=300', '--runs=3', '--sections=3', '--profile=small', '--database='.$database]);

    try {
        $process->run();
        $output = $process->getOutput();

        expect($process->getExitCode())->toBe(0, $output.$process->getErrorOutput())
            ->and($output)->toMatch('/^Machine: .+ cores, .+ memory, load .+\.$/m')
            ->and($output)->toContain(sprintf('Scale database %s at ', $database))
            ->and($output)->toContain('Seeded 300 entries of profile small@1 with seed 1 in 3 chunks of up to 100')
            ->and($output)->toMatch('/^Seed wall time: [0-9.]+ s \([0-9]+ entries per second\)\.$/m')
            ->and($output)->toContain("The listing's first page holds 20 rows.")
            ->and($output)->toMatch('/^newest first, first page of 20: median [0-9.]+ ms over 3 runs .+: pass$/m')
            ->and($output)->toMatch('/^newest first, keyset page after it: median [0-9.]+ ms over 3 runs .+: pass$/m');
    } finally {
        DB::connection('pgsql_owner')->statement(sprintf('drop database if exists "%s" with (force)', $database));
    }
});
