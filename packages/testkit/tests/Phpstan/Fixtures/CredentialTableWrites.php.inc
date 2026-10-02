<?php

declare(strict_types=1);

// Writes to the credential store of the local accounts in an addon, the core and a namespace that
// only starts like the identity module's, where they are reported, and in the identity module,
// where they are not.

namespace Acme\Accounts\Adapter {
    use Illuminate\Database\ConnectionInterface;
    use Illuminate\Support\Facades\DB;
    use PDO;

    final readonly class AccountsWriter
    {
        public function __construct(private ConnectionInterface $connection, private PDO $pdo) {}

        public function builder(): void
        {
            $this->connection->table('cms_identity.local_accounts')->insert(['actor_id' => 'a']);
            DB::connection('pgsql_identity')->table('local_accounts')->where('actor_id', 'a')->update(['version' => 2]);
            DB::table('"CMS_IDENTITY"."password_reset_tokens" as t')->delete();
            $this->connection->table('cms_identity.audit_copies')->truncate();
        }

        public function sql(): void
        {
            $this->connection->statement('update cms_identity.local_accounts set version = 2');
            DB::statement('delete from password_reset_tokens where actor_id = ?', ['a']);
            $this->pdo->exec('insert into cms_identity . password_reset_tokens (token_hash) values (1)');
            DB::unprepared('truncate table local_accounts, actors');
        }

        public function allowed(): void
        {
            // Reads, and a table of the same name in another schema.
            $this->connection->table('cms_identity.local_accounts')->count();
            $this->connection->select('select * from cms_identity.local_accounts');
            $this->connection->table('public.local_accounts')->insert(['id' => 'a']);
            $this->connection->statement('insert into shop.password_reset_tokens values (1)');
        }
    }
}

namespace Cbox\Cms\Core\Identity\Adapter {
    use Illuminate\Database\ConnectionInterface;

    final readonly class CoreAccountsWriter
    {
        public function seed(ConnectionInterface $connection): void
        {
            $connection->table('cms_identity.local_accounts')->insert(['actor_id' => 'a']);
        }
    }
}

namespace Cbox\Cms\Identity\CredentialStore\Adapter {
    use Illuminate\Database\ConnectionInterface;

    final readonly class IdentityAccountsWriter
    {
        public function seed(ConnectionInterface $connection): void
        {
            $connection->table('cms_identity.local_accounts')->insert(['actor_id' => 'a']);
            $connection->statement('delete from cms_identity.password_reset_tokens where used_at is not null');
        }
    }
}

namespace Cbox\Cms\IdentityExtras {
    use Illuminate\Database\ConnectionInterface;

    final readonly class NotTheIdentityModule
    {
        public function seed(ConnectionInterface $connection): void
        {
            $connection->table('local_accounts')->insert(['actor_id' => 'a']);
        }
    }
}
