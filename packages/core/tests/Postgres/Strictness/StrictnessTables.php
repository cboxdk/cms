<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres\Strictness;

use Illuminate\Support\Facades\DB;

/**
 * The scratch tables of the Eloquent strictness tests, made by the owner role, so the app role
 * gets the owner's default privileges on them, and dropped after each test.
 */
final class StrictnessTables
{
    public const string PARENTS = 'strictness_parents';

    public const string CHILDREN = 'strictness_children';

    public static function create(): void
    {
        self::drop();

        $owner = DB::connection('pgsql_owner');
        $owner->statement(sprintf("create table %s (id bigint primary key, name text not null, note text not null default '')", self::PARENTS));
        $owner->statement(sprintf(
            'create table %s (id bigint primary key, parent_id bigint not null references %s (id), label text not null)',
            self::CHILDREN,
            self::PARENTS,
        ));
        $owner->statement(sprintf('create index on %s (parent_id)', self::CHILDREN));
    }

    public static function drop(): void
    {
        $owner = DB::connection('pgsql_owner');
        $owner->statement(sprintf('drop table if exists %s', self::CHILDREN));
        $owner->statement(sprintf('drop table if exists %s', self::PARENTS));
    }

    /**
     * Two parents with two children each, written as the app role.
     */
    public static function seed(): void
    {
        DB::table(self::PARENTS)->insert([
            ['id' => 1, 'name' => 'A', 'note' => 'first'],
            ['id' => 2, 'name' => 'B', 'note' => 'second'],
        ]);
        DB::table(self::CHILDREN)->insert([
            ['id' => 1, 'parent_id' => 1, 'label' => 'A1'],
            ['id' => 2, 'parent_id' => 1, 'label' => 'A2'],
            ['id' => 3, 'parent_id' => 2, 'label' => 'B1'],
            ['id' => 4, 'parent_id' => 2, 'label' => 'B2'],
        ]);
    }
}
