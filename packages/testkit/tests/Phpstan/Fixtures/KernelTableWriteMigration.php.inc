<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;

// A migration in the global namespace that writes a kernel table: reported unless the file lies in
// the core module's directory.

function seedKernelTable(ConnectionInterface $connection): void
{
    $connection->statement("insert into actors (id) values ('a')");
    $connection->table('sites')->insert(['handle' => 'main']);
}
