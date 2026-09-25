<?php

declare(strict_types=1);

namespace Cbox\Cms\Testkit\Tests\Postgres;

use Cbox\Cms\Testkit\Postgres\ChildProcesses;
use Cbox\Cms\Testkit\Postgres\ProcessContext;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\AssertionFailedError;

/*
 * The process helper: a closure or script in a separate PHP process against its own
 * connection, for scenarios where one caller blocks while another holds a lock.
 */

/**
 * Runs $callback and returns the AssertionFailedError the helper throws for it.
 *
 * @param  Closure(): void  $callback
 */
function childFailure(Closure $callback): AssertionFailedError
{
    try {
        $callback();
    } catch (AssertionFailedError $failure) {
        return $failure;
    }

    throw new AssertionFailedError('The child process did not fail.');
}

it('makes the parent wait while a child holds pg_advisory_xact_lock(1) for 500 ms', function (): void {
    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context): void {
        $connection = $context->connection();
        $connection->beginTransaction();
        $connection->select('select pg_advisory_xact_lock(1)');
        $context->signal('locked');
        $connection->select('select pg_sleep(0.5)');
        $connection->commit();
    });

    $child->waitForSignal('locked');

    $started = hrtime(true);
    DB::transaction(static function (): void {
        DB::select('select pg_advisory_xact_lock(1)');
    });
    $waitedMs = (hrtime(true) - $started) / 1e6;

    $child->wait();

    expect($waitedMs)->toBeGreaterThanOrEqual(400.0)
        ->and($child->signals())->toBe(['locked']);
});

it('runs the child on its own backend as the same role, with what the closure captured', function (): void {
    $parentBackend = DB::scalar('select pg_backend_pid()');
    $greeting = 'from the parent';

    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context) use ($greeting): void {
        $connection = $context->connection();
        $context->signal('backend='.json_encode($connection->scalar('select pg_backend_pid()')));
        $context->signal('role='.json_encode($connection->scalar('select current_user')));
        echo 'greeting=', $greeting, "\n";
    });
    $child->wait();

    expect($child->signals())->toHaveCount(2)
        ->and($child->signals()[1] ?? null)->toBe('role="cms_app"')
        ->and($child->signals()[0] ?? null)->toMatch('/^backend=\d+$/')
        ->and($child->signals()[0] ?? null)->not->toBe('backend='.json_encode($parentBackend))
        ->and($child->output())->toBe("greeting=from the parent\n");
});

it('commits for real in the child, so the parent sees the row', function (): void {
    $table = Probe::table();

    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context) use ($table): void {
        $context->connection()->table($table)->insert(['note' => 'written by the child']);
    });
    $child->wait();

    expect(DB::table($table)->where('note', 'written by the child')->count())->toBe(1);
});

it('runs a script that returns a closure', function (): void {
    $child = app(ChildProcesses::class)->start(__DIR__.'/scripts/hold-lock.php');
    $child->waitForSignal('locked');

    $started = hrtime(true);
    DB::select('select pg_advisory_lock(2)');
    DB::select('select pg_advisory_unlock(2)');
    $waitedMs = (hrtime(true) - $started) / 1e6;

    $child->wait();

    expect($waitedMs)->toBeGreaterThanOrEqual(200.0);
});

it('fails the test with the child\'s error when the child throws', function (): void {
    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context): void {
        $context->connection()->select('select 1 from no_such_table');
    });

    $failure = childFailure(static fn () => $child->wait());

    expect($failure->getMessage())
        ->toContain('The child process failed.')
        ->toContain('Exit code: 1')
        ->toContain('no_such_table');
});

it('fails when the child exits before it signals', function (): void {
    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context): void {});

    expect(childFailure(static fn () => $child->waitForSignal('never'))->getMessage())
        ->toContain('The child process exited before it signalled [never].')
        ->toContain('Exit code: 0');
});

it('fails and stops the child when a signal does not come in time', function (): void {
    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context): void {
        $context->connection()->select('select pg_sleep(5)');
    });

    $started = hrtime(true);
    $failure = childFailure(static fn () => $child->waitForSignal('never', 0.3));

    expect($failure->getMessage())->toContain('did not signal [never] within 0.3 s')
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(3.0)
        ->and($child->isRunning())->toBeFalse();
});

it('guards the child\'s connection against nested transactions too', function (): void {
    $child = app(ChildProcesses::class)->start(static function (ProcessContext $context): void {
        $connection = $context->connection();
        $connection->transaction(static function () use ($connection): void {
            $connection->transaction(static function (): void {});
        });
    });

    expect(childFailure(static fn () => $child->wait())->getMessage())->toContain('SAVEPOINT trans2');
});

it('fails with exit code 2 when a script does not return a closure', function (): void {
    $script = tempnam(sys_get_temp_dir(), 'cms-child-');
    file_put_contents($script, "<?php\n\ndeclare(strict_types=1);\n\nreturn 42;\n");

    try {
        $child = app(ChildProcesses::class)->start($script);

        expect(childFailure(static fn () => $child->wait())->getMessage())
            ->toContain('Exit code: 2')
            ->toContain('does not return a closure');
    } finally {
        unlink($script);
    }
});

it('refuses a closure that uses $this, which does not exist in the child', function (): void {
    app(ChildProcesses::class)->start(function (ProcessContext $context): void {
        $context->signal($this::class);
    });
})->throws(InvalidArgumentException::class, 'cannot use $this');

it('refuses a script that does not exist', function (): void {
    app(ChildProcesses::class)->start(__DIR__.'/scripts/missing.php');
})->throws(InvalidArgumentException::class, 'does not exist');
