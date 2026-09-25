<?php

declare(strict_types=1);

use Cbox\Cms\Testkit\Postgres\ProcessContext;

/*
 * A child script for ChildProcessesTest: holds advisory lock 2 in a transaction for 300 ms.
 */

return static function (ProcessContext $context): void {
    $connection = $context->connection();
    $connection->beginTransaction();
    $connection->select('select pg_advisory_xact_lock(2)');
    $context->signal('locked');
    $connection->select('select pg_sleep(0.3)');
    $connection->commit();
};
