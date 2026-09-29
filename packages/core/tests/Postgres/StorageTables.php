<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Postgres;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use UnexpectedValueException;

/**
 * Helpers for the tests of the structure and entry tables: the superuser's connection to the
 * checkout's database, the one role that passes their row level security while they have no policy,
 * rows to write through it, and the SQLSTATE of a statement that fails.
 */
final class StorageTables
{
    public const string SUPERUSER = 'pgsql_storage_superuser';

    /** @var list<string> sorted */
    public const array TABLES = ['entries', 'node_routes', 'nodes', 'site_locales', 'sites', 'variant_heads'];

    public const string ROOT = '0192a0c0-0000-7000-8000-000000000001';

    public const string SECTION = '0192a0c0-0000-7000-8000-000000000002';

    public const string MOUNT = '0192a0c0-0000-7000-8000-000000000003';

    public const string SITE = '0192a0c0-0000-7000-8000-000000000010';

    public const string ENTRY = '0192a0c0-0000-7000-8000-000000000020';

    public const string TYPE = '0192a0c0-0000-7000-8000-000000000030';

    public const string CREATED_AT = '2026-03-10 12:00:00+00';

    public static function superuser(): Connection
    {
        config(['database.connections.'.self::SUPERUSER => array_merge((array) config('database.connections.pgsql'), [
            'username' => self::env('DB_SUPERUSER_USERNAME'),
            'password' => self::env('DB_SUPERUSER_PASSWORD'),
        ])]);

        return DB::connection(self::SUPERUSER);
    }

    /**
     * The ltree label of a node: its id as 32 hex digits.
     */
    public static function label(string $id): string
    {
        return str_replace('-', '', $id);
    }

    /**
     * A node row under the parent's path, or a root without one.
     *
     * @return array<string, int|string|null>
     */
    public static function node(string $id, ?string $parent = null, ?string $parentPath = null, string $kind = 'section', ?string $mountSource = null): array
    {
        return [
            'id' => $id,
            'parent_id' => $parent,
            'kind' => $kind,
            'path' => $parentPath === null ? self::label($id) : $parentPath.'.'.self::label($id),
            'mount_source_id' => $mountSource,
            'version' => 1,
            'created_at' => self::CREATED_AT,
        ];
    }

    /**
     * A root, a section below it and a site on the root in the locale da, written as the superuser.
     */
    public static function seedStructure(): void
    {
        $superuser = self::superuser();
        $superuser->table('nodes')->insert(self::node(self::ROOT, kind: 'site'));
        $superuser->table('nodes')->insert(self::node(self::SECTION, self::ROOT, self::label(self::ROOT)));
        $superuser->table('sites')->insert(['id' => self::SITE, 'handle' => 'north', 'root_node_id' => self::ROOT, 'version' => 1, 'created_at' => self::CREATED_AT]);
        $superuser->table('site_locales')->insert(['site_id' => self::SITE, 'locale' => 'da', 'created_at' => self::CREATED_AT]);
        $superuser->table('node_routes')->insert(self::route(self::ROOT, '/'));
    }

    /**
     * @return array<string, string>
     */
    public static function route(string $node, string $route, string $locale = 'da'): array
    {
        return ['site_id' => self::SITE, 'locale' => $locale, 'route' => $route, 'node_id' => $node, 'created_at' => self::CREATED_AT];
    }

    /**
     * The seeded structure, an entry homed on the section and its shared variant's head.
     */
    public static function seedEntry(): void
    {
        self::seedStructure();
        $superuser = self::superuser();
        $superuser->table('entries')->insert(self::entry());
        $superuser->table('variant_heads')->insert(self::head());
    }

    /**
     * @return array<string, int|string|null>
     */
    public static function entry(string $id = self::ENTRY): array
    {
        return [
            'id' => $id,
            'type_id' => self::TYPE,
            'home_node_id' => self::SECTION,
            'owner_actor_id' => null,
            'lifecycle' => 'active',
            'version' => 1,
            'created_at' => self::CREATED_AT,
        ];
    }

    /**
     * @param  array<mixed>  $changes
     * @return array<mixed>
     */
    public static function head(array $changes = []): array
    {
        return array_merge([
            'entry_id' => self::ENTRY,
            'variant' => 'shared',
            'draft_revision_id' => 1,
            'published_revision_id' => null,
            'schema_version' => 1,
            'release_state' => 'unreleased',
            'workflow_state' => null,
            'next_transition_at' => null,
            'version' => 1,
            'created_at' => self::CREATED_AT,
        ], $changes);
    }

    /**
     * The SQLSTATE of the query exception the call throws.
     *
     * @param  Closure(): mixed  $call
     */
    public static function sqlState(Closure $call): string
    {
        try {
            $call();
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;

            return is_string($state) ? $state : throw new AssertionFailedError('The exception has no SQLSTATE.', $exception->getCode(), $exception);
        }

        throw new AssertionFailedError('The statement did not fail.');
    }

    /**
     * The SQLSTATE of the query exception the call throws and the constraint it names, such as
     * "23514 nodes_root".
     *
     * @param  Closure(): mixed  $call
     */
    public static function violation(Closure $call): string
    {
        try {
            $call();
        } catch (QueryException $exception) {
            $state = $exception->errorInfo[0] ?? null;

            if (! is_string($state) || preg_match('/violates (?:check|unique|foreign key) constraint "([a-z0-9_]+)"/', $exception->getMessage(), $matches) !== 1) {
                throw new AssertionFailedError('The exception names no constraint: '.$exception->getMessage(), $exception->getCode(), $exception);
            }

            return $state.' '.$matches[1];
        }

        throw new AssertionFailedError('The statement did not fail.');
    }

    /**
     * The value column of each row of a query.
     *
     * @param  list<string>  $bindings
     * @return list<string>
     */
    public static function texts(Connection $connection, string $sql, array $bindings = []): array
    {
        return array_map(
            static fn (mixed $row): string => is_object($row) && isset($row->value) && is_string($row->value) ? $row->value : throw new UnexpectedValueException('Expected a text value.'),
            array_values($connection->select($sql, $bindings)),
        );
    }

    private static function env(string $name): string
    {
        $value = Env::get($name);

        return is_string($value) && $value !== '' ? $value : throw new UnexpectedValueException(sprintf('%s is not set.', $name));
    }
}
