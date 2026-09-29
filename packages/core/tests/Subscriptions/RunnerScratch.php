<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Subscriptions;

use Cbox\Cms\Contracts\Events\StoredEvent;
use Cbox\Cms\Contracts\Subscribers\Delivery;
use Illuminate\Support\Facades\DB;

/**
 * The scratch table the runner's Postgres tests' subscriber writes to, on the default connection,
 * in the runner's batch: one row per event it handled, made by the owner role, so the app role
 * gets the owner's default privileges on it, and dropped after each test.
 */
final class RunnerScratch
{
    public const string TABLE = 'runner_scratch_handled';

    public static function create(): void
    {
        self::drop();

        DB::connection('pgsql_owner')->statement(sprintf(
            'create table %s (aggregate_id text not null, version bigint not null, attempt integer not null, release boolean not null, primary key (aggregate_id, version))',
            self::TABLE,
        ));
    }

    public static function drop(): void
    {
        DB::connection('pgsql_owner')->statement(sprintf('drop table if exists %s', self::TABLE));
    }

    /**
     * The subscriber's write for an event, as the app role on the default connection.
     */
    public static function write(StoredEvent $event, Delivery $delivery): void
    {
        DB::table(self::TABLE)->insert([
            'aggregate_id' => $event->aggregate->id->value,
            'version' => $event->aggregate->version,
            'attempt' => $delivery->attempt,
            'release' => $delivery->release,
        ]);
    }

    /**
     * The committed rows, as "<aggregate id>@<version>[ release]", sorted.
     *
     * @return list<string>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (DB::table(self::TABLE)->orderBy('aggregate_id')->orderBy('version')->get() as $row) {
            $id = is_string($row->aggregate_id ?? null) ? $row->aggregate_id : '';
            $version = is_int($row->version ?? null) ? $row->version : 0;
            $rows[] = sprintf('%s@%d%s', $id, $version, ($row->release ?? false) === true ? ' release' : '');
        }

        return $rows;
    }
}
