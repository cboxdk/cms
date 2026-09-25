<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Arch\Codebase;
use Cbox\Cms\Tests\Support\Arch\Layer;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/*
 * The kernel owns the commit (GUARDRAILS 2.1, 4.1): an action never writes, and a job works
 * through actions. Neither reaches for the DB facade or a connection.
 */

arch('database: actions and jobs do not use the DB facade or a database connection', function (): void {
    Rules::forbid(Codebase::classesIn(Layer::Actions, Layer::Jobs), [
        DB::class,
        Connection::class,
        ConnectionInterface::class,
        ConnectionResolverInterface::class,
        DatabaseManager::class,
    ]);
});
