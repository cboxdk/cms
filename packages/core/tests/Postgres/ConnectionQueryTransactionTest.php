<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Core\Reads\Adapter\ConnectionQueryTransaction;
use Cbox\Cms\Core\Reads\Domain\QueryTransaction;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCount;
use Cbox\Cms\Testkit\Postgres\IndependentConnections;
use Illuminate\Support\Facades\DB;

/*
 * The read transaction on Postgres (PRD 6.2, 8.4): it runs at REPEATABLE READ whatever the
 * connection's default, so every statement of the read sees one snapshot, and its position is that
 * snapshot's xmin, which a commit after the read began does not move.
 */

afterEach(function (): void {
    app(IndependentConnections::class)->closeAll();
});

it('is the container\'s read transaction and reads at repeatable read with the snapshot\'s xmin as its position', function (): void {
    $transaction = app(QueryTransaction::class);
    $app = DB::connection();
    $seen = [];

    $transaction->run(function () use ($transaction, $app, &$seen): QueryResult {
        $seen['isolation'] = $app->scalar('select current_setting(\'transaction_isolation\')');
        $seen['position'] = $transaction->position();
        $seen['xmin'] = $app->scalar('select pg_snapshot_xmin(pg_current_snapshot())::text');

        return QueryResult::answered(new ProbeCount(0), [], $seen['position']);
    });

    expect($transaction)->toBeInstanceOf(ConnectionQueryTransaction::class)
        ->and($seen['isolation'])->toBe('repeatable read')
        ->and($seen['position'])->toBeInstanceOf(CommitPosition::class)
        ->and($seen['position'] instanceof CommitPosition ? $seen['position']->value : null)->toBe($seen['xmin'])
        ->and($app->transactionLevel())->toBe(0);
});

it('keeps one position for the whole read while a transaction that was running when it began commits', function (): void {
    $transaction = app(QueryTransaction::class);
    [$writer] = app(IndependentConnections::class)->open(1);
    $writer->beginTransaction();
    $xid = $writer->scalar('select pg_current_xact_id()::text');
    $running = new CommitPosition(is_string($xid) ? $xid : '');
    $positions = [];

    $transaction->run(function () use ($transaction, $writer, &$positions): QueryResult {
        $positions[] = $transaction->position();
        $writer->commit();
        $positions[] = $transaction->position();

        return QueryResult::answered(new ProbeCount(0), [], $positions[0]);
    });

    // The writer was running when the read began, so the snapshot's xmin is at most its xid, and
    // its commit during the read does not move the read's position past it.
    expect($positions[0]->equals($positions[1]))->toBeTrue()
        ->and($running->isBelow($positions[0]))->toBeFalse();
});
