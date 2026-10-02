<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;

// A migration in the global namespace below the core module's directory that writes a kernel
// table: the testkit's KernelTableWriteRule allows it with its default core directory.

function seedKernelTableInTheCore(ConnectionInterface $connection): void
{
    $connection->statement("insert into actors (id) values ('a')");
    $connection->table('sites')->insert(['handle' => 'main']);
}
