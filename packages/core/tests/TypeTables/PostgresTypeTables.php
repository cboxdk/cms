<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\TypeTables;

use Cbox\Cms\Contracts\Identity\AccessContext;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Schema\ColumnDefinition;
use Cbox\Cms\Contracts\Schema\FieldDefinition;
use Cbox\Cms\Contracts\Schema\TypeDefinition;
use Cbox\Cms\Contracts\TypeTables\TypeTablePage;
use Cbox\Cms\Contracts\TypeTables\TypeTableQuery;
use Cbox\Cms\Contracts\TypeTables\TypeTableReader;
use Cbox\Cms\Core\Access\Infrastructure\ActorContext;
use Cbox\Cms\Core\Access\Infrastructure\TypeTableAccess;
use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Core\TypeTables\Boundary\TypeTableColumns;
use Cbox\Cms\Testkit\TypeTables\TypeTableSeed;
use Illuminate\Support\Facades\DB;
use Override;

/**
 * Type tables on the checkout's test database for the tests of the TypeTableReader (PRD 11.6):
 * create() makes the table of a type the migrations do not know, as the owner role, with the
 * system columns and the row level security of a generated type table (TypeTableAccess); seed()
 * writes released rows of the shared variant with their nodes, owners and entries as the
 * superuser, the fields through TypeTableColumns; and inContext() wraps a reader so each page runs
 * in a transaction of the app role with the context's actor context set, as the query pipeline
 * runs it.
 */
final class PostgresTypeTables
{
    public const string CREATED_AT = '2026-03-10 12:00:00+00';

    /**
     * Creates the type's table afresh, as a generated migration would.
     */
    public static function create(TypeDefinition $type): void
    {
        $owner = DB::connection('pgsql_owner');
        $table = $type->name->table();
        $columns = array_map(
            static fn (ColumnDefinition $column): string => sprintf('"%s" %s%s', $column->name, $column->type, $column->notNull ? ' not null' : ''),
            array_values(array_filter(array_map(static fn (FieldDefinition $field): ?ColumnDefinition => $field->column, $type->fields))),
        );

        self::drop($type);
        $owner->statement(sprintf(
            'create table "%s" (cms_entry_id uuid not null references entries (id), cms_locale text not null, cms_stage text not null, cms_home_node uuid not null references nodes (id), cms_owner_actor uuid references actors (id), %s, primary key (cms_entry_id, cms_locale, cms_stage))',
            $table,
            implode(', ', $columns),
        ));
        new TypeTableAccess($owner)->protect($table);
    }

    public static function drop(TypeDefinition $type): void
    {
        DB::connection('pgsql_owner')->statement(sprintf('drop table if exists "%s"', $type->name->table()));
    }

    /**
     * Writes the rows, and the nodes of their home paths, their owners and their entries where
     * they are missing.
     */
    public static function seed(TypeDefinition $type, TypeTableSeed ...$rows): void
    {
        $superuser = StorageTables::superuser();

        foreach ($rows as $row) {
            $labels = explode('.', $row->home->value);
            $parent = null;

            foreach ($labels as $depth => $label) {
                $id = self::id($label);
                $superuser->table('nodes')->insertOrIgnore([
                    'id' => $id,
                    'parent_id' => $parent,
                    'kind' => $depth === 0 ? 'site' : 'section',
                    'path' => implode('.', array_slice($labels, 0, $depth + 1)),
                    'mount_source_id' => null,
                    'version' => 1,
                    'created_at' => self::CREATED_AT,
                ]);
                $parent = $id;
            }

            if ($row->owner instanceof ActorId) {
                $superuser->table('actors')->insertOrIgnore(['id' => $row->owner->toString(), 'actor_class' => 'staff', 'state' => 'active', 'version' => 1, 'credential_generation' => 1, 'created_at' => self::CREATED_AT]);
            }

            $superuser->table('entries')->insertOrIgnore([
                'id' => $row->entry->toString(),
                'type_id' => $type->id->toString(),
                'home_node_id' => $parent,
                'owner_actor_id' => $row->owner?->toString(),
                'lifecycle' => 'active',
                'version' => 1,
                'created_at' => self::CREATED_AT,
            ]);
            $superuser->table($type->name->table())->insert([
                'cms_entry_id' => $row->entry->toString(),
                'cms_locale' => 'shared',
                'cms_stage' => 'released',
                'cms_home_node' => $parent,
                'cms_owner_actor' => $row->owner?->toString(),
                ...TypeTableColumns::encode($type, $row->fields),
            ]);
        }
    }

    /**
     * The reader with each page run in its own transaction of the app role, after the context was
     * set with SET LOCAL.
     */
    public static function inContext(TypeTableReader $reader): TypeTableReader
    {
        return new readonly class($reader) implements TypeTableReader
        {
            public function __construct(private TypeTableReader $reader) {}

            #[Override]
            public function page(TypeTableQuery $query, AccessContext $access): TypeTablePage
            {
                return DB::connection()->transaction(function () use ($query, $access): TypeTablePage {
                    app(ActorContext::class)->set($access);

                    return $this->reader->page($query, $access);
                });
            }
        };
    }

    /**
     * The id of a node from its label, 32 hex digits.
     */
    public static function id(string $label): string
    {
        return implode('-', [substr($label, 0, 8), substr($label, 8, 4), substr($label, 12, 4), substr($label, 16, 4), substr($label, 20)]);
    }
}
