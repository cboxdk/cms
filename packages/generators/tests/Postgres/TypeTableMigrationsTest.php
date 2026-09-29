<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Postgres;

use Cbox\Cms\Core\Tests\Postgres\StorageTables;
use Cbox\Cms\Generators\Migrations\Boundary\TypeTableLockJson;
use Cbox\Cms\Generators\Migrations\Domain\Dto\LockedColumn;
use Cbox\Cms\Generators\Migrations\Domain\Dto\TypeTableLock;
use Cbox\Cms\Generators\Migrations\Domain\MigrationSource;
use Cbox\Cms\Generators\Tests\Descriptor\ComprehensiveExample;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use LogicException;

/*
 * The comprehensive example's generated migration on Postgres (PRD 4.1, 4.2, 11.6): the owner
 * role applies the committed golden migration, and the catalog shows the columns with their types
 * and NOT NULL, the indexes, fillfactor 80, forced row level security with the two policies of
 * every type table and the app role's narrowed grants. A row with only the required values fits the
 * checks, a value outside a select's options does not, and a later step adds a nullable column
 * with a valid index built concurrently. down() removes what up() made.
 */

const PRODUCT_CREATE = ComprehensiveExample::DIRECTORY.'/'.ComprehensiveExample::MIGRATIONS_DIRECTORY.'/shop__product_0001_create.php';

const PRODUCT_LOCK = ComprehensiveExample::DIRECTORY.'/'.ComprehensiveExample::MIGRATIONS_DIRECTORY.'/shop__product.lock';

afterEach(function (): void {
    DB::purge(StorageTables::SUPERUSER);
    SchemaFixtures::cleanUp();
});

/**
 * Runs up() or down() of the migration in the file as the owner role, as `migrate` does.
 */
function asOwner(string $file, string $method): void
{
    $migration = require $file;

    if (! $migration instanceof Migration || ! method_exists($migration, $method)) {
        throw new LogicException(sprintf('%s is not a migration with %s().', $file, $method));
    }

    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('pgsql_owner');

    try {
        $migration->{$method}();
    } finally {
        DB::setDefaultConnection($default);
    }
}

/**
 * The value column of each row the owner reads.
 *
 * @param  list<string>  $bindings
 * @return list<string>
 */
function ownerReads(string $sql, array $bindings = []): array
{
    return StorageTables::texts(DB::connection('pgsql_owner'), $sql, $bindings);
}

/**
 * @param  Closure(): void  $test
 */
function withProductTable(Closure $test): void
{
    asOwner(PRODUCT_CREATE, 'up');

    try {
        $test();
    } finally {
        asOwner(PRODUCT_CREATE, 'down');
    }
}

it('applies the comprehensive example\'s migration and reads its columns, indexes, fillfactor, row level security and grants back', function (): void {
    withProductTable(function (): void {
        $app = DB::connection()->getConfig('username');
        $app = is_string($app) ? $app : throw new LogicException('The app connection has no username.');

        expect(ownerReads(<<<'SQL'
            select attname::text || ' ' || format_type(atttypid, atttypmod) || case when attnotnull then ' not null' else '' end as value
            from pg_attribute where attrelid = 'shop__product'::regclass and attnum > 0 and not attisdropped order by attnum
            SQL))->toBe([
            'cms_entry_id uuid not null',
            'cms_locale text not null',
            'cms_stage text not null',
            'cms_home_node uuid not null',
            'cms_owner_actor uuid',
            'body jsonb',
            'care jsonb',
            'checked_at timestamp with time zone',
            'colour text not null',
            'dimensions jsonb',
            'discontinued boolean',
            'ext__app__name text',
            'ext__app__tax_code text',
            'launch_date date',
            'name text not null',
            'price numeric(10,2) not null',
            'purchase_price bytea',
            'stock bigint',
            'summary text',
            'supplier bytea',
            'support_email text',
            'tags text[]',
            'weight bigint',
        ])
            ->and(ownerReads(<<<'SQL'
                select i.relname::text || ' ' || (
                    select string_agg(a.attname::text, ', ' order by k.ord)
                    from unnest(x.indkey::int2[]) with ordinality k (attnum, ord)
                    join pg_attribute a on a.attrelid = x.indrelid and a.attnum = k.attnum
                ) || case when x.indisprimary then ' primary' else '' end || case when x.indisvalid then '' else ' invalid' end as value
                from pg_index x join pg_class i on i.oid = x.indexrelid
                where x.indrelid = 'shop__product'::regclass order by 1
                SQL))->toBe([
                'shop__product__cms_home_node cms_home_node',
                'shop__product__cms_owner_actor cms_owner_actor',
                'shop__product__colour cms_stage, cms_locale, colour, cms_entry_id',
                'shop__product__discontinued cms_stage, cms_locale, discontinued, cms_entry_id',
                'shop__product__ext__app__tax_code cms_stage, cms_locale, ext__app__tax_code, cms_entry_id',
                'shop__product__launch_date cms_stage, cms_locale, launch_date, cms_entry_id',
                'shop__product__name cms_stage, cms_locale, name, cms_entry_id',
                'shop__product__price cms_stage, cms_locale, price, cms_entry_id',
                'shop__product__stock cms_stage, cms_locale, stock, cms_entry_id',
                'shop__product_pkey cms_entry_id, cms_locale, cms_stage primary',
            ])
            ->and(ownerReads("select array_to_string(reloptions, ',') || ' ' || relrowsecurity::text || ' ' || relforcerowsecurity::text as value from pg_class where oid = 'shop__product'::regclass"))
            ->toBe(['fillfactor=80 true true'])
            ->and(ownerReads("select policyname::text || ' ' || cmd::text as value from pg_policies where tablename = 'shop__product' order by 1"))
            ->toBe(['shop__product_actor ALL', 'shop__product_released SELECT'])
            ->and(ownerReads(<<<'SQL'
                select f.relname::text as value from pg_constraint c join pg_class f on f.oid = c.confrelid
                where c.conrelid = 'shop__product'::regclass and c.contype = 'f' order by 1
                SQL))->toBe(['actors', 'entries', 'nodes'])
            ->and(ownerReads(<<<'SQL'
                select a.privilege_type::text as value
                from pg_class c, aclexplode(c.relacl) a
                where c.oid = 'shop__product'::regclass and a.grantee = (select oid from pg_roles where rolname = ?)
                order by 1
                SQL, [$app]))->toBe(['DELETE', 'INSERT', 'SELECT', 'UPDATE']);
    });
});

it('takes a row with only the required values, a repeated group left out included, and refuses values outside the checks', function (): void {
    StorageTables::seedEntry();
    $superuser = StorageTables::superuser();

    withProductTable(function () use ($superuser): void {
        $row = [
            'cms_entry_id' => StorageTables::ENTRY,
            'cms_locale' => 'shared',
            'cms_stage' => 'draft',
            'cms_home_node' => StorageTables::SECTION,
            'colour' => 'red',
            'name' => 'Lamp',
            'price' => '12.50',
        ];
        $superuser->table('shop__product')->insert($row);

        expect($superuser->table('shop__product')->count())->toBe(1)
            ->and(StorageTables::sqlState(static fn (): bool => $superuser->table('shop__product')->insert([...$row, 'cms_stage' => 'released', 'colour' => 'purple'])))->toBe('23514')
            ->and(StorageTables::sqlState(static fn (): bool => $superuser->table('shop__product')->insert([...$row, 'cms_stage' => 'staged', 'cms_locale' => 'da'])))->toBe('23514')
            ->and(StorageTables::sqlState(static fn (): bool => $superuser->table('shop__product')->insert([...$row, 'cms_stage' => 'published'])))->toBe('23514')
            ->and(StorageTables::sqlState(static fn (): bool => $superuser->table('shop__product')->insert([...$row, 'cms_stage' => 'released', 'dimensions' => '{"size": "small"}'])))->toBe('23514')
            ->and(StorageTables::sqlState(static fn (): bool => $superuser->table('shop__product')->insert($row)))->toBe('23505');

        $superuser->table('shop__product')->delete();
    });
});

it('adds a later step\'s nullable column with a valid index built concurrently, and down() drops it', function (): void {
    $golden = TypeTableLockJson::decode('migrations/cms/shop__product.lock', (string) file_get_contents(PRODUCT_LOCK));
    $lock = new TypeTableLock($golden->table, $golden->type, $golden->typeId, $golden->stages, $golden->localization, 2, [
        ...$golden->columns,
        new LockedColumn('ext__app__code', 'text', false, ['char_length("ext__app__code") <= 20'], true, 2),
    ]);
    $file = SchemaFixtures::scratch().'/'.MigrationSource::name($lock, 2).'.php';
    file_put_contents($file, MigrationSource::source($lock, 2));

    withProductTable(function () use ($file): void {
        asOwner($file, 'up');
        $added = ownerReads("select format_type(atttypid, atttypmod) || ' ' || attnotnull::text as value from pg_attribute where attrelid = 'shop__product'::regclass and attname = 'ext__app__code'");
        $index = ownerReads("select indisvalid::text as value from pg_index where indexrelid = 'shop__product__ext__app__code'::regclass");
        $timeout = ownerReads('select setting as value from pg_settings where name = \'lock_timeout\'');
        asOwner($file, 'up');
        $again = ownerReads("select count(*)::text as value from pg_index where indrelid = 'shop__product'::regclass");
        asOwner($file, 'down');

        expect($added)->toBe(['text false'])
            ->and($index)->toBe(['true'])
            ->and($timeout)->toBe(['0'])
            ->and($again)->toBe(['11'])
            ->and(ownerReads("select count(*)::text as value from pg_attribute where attrelid = 'shop__product'::regclass and attname = 'ext__app__code' and not attisdropped"))->toBe(['0'])
            ->and(ownerReads("select count(*)::text as value from pg_class where relname = 'shop__product__ext__app__code'"))->toBe(['0']);
    });
});
