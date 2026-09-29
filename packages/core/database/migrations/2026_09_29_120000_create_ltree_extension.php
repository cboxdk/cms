<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The ltree extension (PRD 5.8, 5.10), created by the owner role in the schema it runs in: the
 * paths of the node tree are ltree values with a GiST index, and the row level security of the
 * content tables tests them. ltree is a trusted extension, so the owner role needs no superuser,
 * only CREATE on the database, which it has as the database's owner (docker/postgres/sql/database.sql
 * and the testkit's TestDatabaseSetup make it the owner). The doctor's postgres.extensions fails
 * while it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection($this->getConnection())->statement('create extension if not exists ltree');
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('drop extension if exists ltree');
    }
};
