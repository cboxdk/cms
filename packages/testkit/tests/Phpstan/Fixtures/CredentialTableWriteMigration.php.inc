<?php

declare(strict_types=1);

use Illuminate\Database\ConnectionInterface;

// A migration in the global namespace that writes the credential store: reported unless the file
// lies in the identity module's directory.

function seedCredentialStore(ConnectionInterface $connection): void
{
    $connection->statement("insert into cms_identity.local_accounts (actor_id) values ('a')");
    $connection->table('password_reset_tokens')->insert(['token_hash' => 'a']);
}
