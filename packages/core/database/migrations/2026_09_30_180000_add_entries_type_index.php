<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The index on entries (type_id, id), for the rebuild of a type's read model (PRD 4.1, invariant
 * 22): it plans its chunks by reading a type's entry ids in id order, a range at a time, which
 * this index answers without reading the entries of other types. Neither column changes after an
 * entry is created, so the index costs a save nothing (PRD 4.2).
 *
 * It is built concurrently, outside a transaction and with a lock_timeout of 2 s, so it never
 * blocks the table behind a long query, and IF NOT EXISTS lets it run again after a failure
 * (PRD 4.2, "Indeks-DDL").
 */
return new class extends Migration
{
    /** CREATE INDEX CONCURRENTLY cannot run inside a transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        $connection = DB::connection($this->getConnection());
        $connection->statement("set lock_timeout = '2s'");

        try {
            $connection->statement('create index concurrently if not exists entries_type_id on entries (type_id, id)');
        } finally {
            $connection->statement('reset lock_timeout');
        }
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop index concurrently if exists entries_type_id');
    }
};
