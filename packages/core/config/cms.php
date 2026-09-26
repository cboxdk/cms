<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdempotencyStore;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Core\Clock\Adapter\SystemClock;
use Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore;
use Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator;
use Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore;

return [
    /*
     * The implementation of each contract (GUARDRAILS 2.3). An application overrides an entry in
     * its own config/cms.php; the entries it leaves out keep these defaults. The class is resolved
     * from the container when the contract is first resolved, as a singleton.
     */
    'contracts' => [
        Clock::class => SystemClock::class,
        IdGenerator::class => SystemIdGenerator::class,
        ReceiptStore::class => PostgresReceiptStore::class,
        IdempotencyStore::class => PostgresIdempotencyStore::class,
    ],

    'database' => [
        /*
         * The connection of the owner role, which owns the schema and runs migrations and partition
         * maintenance (PRD 4.2). The app role on the default connection has no DDL, and the
         * partition manager refuses to run on it.
         */
        'owner_connection' => 'pgsql_owner',

        /*
         * Range partitions kept by `cms:partitions:maintain`, which the scheduler runs every hour.
         * Partitions exist from the span that holds now to runway_days ahead; a partition is removed,
         * with DETACH PARTITION CONCURRENTLY and DROP TABLE, when its span ended retention_days ago.
         * Every DDL statement runs with lock_timeout lock_timeout_ms and is tried up to attempts
         * times, waiting backoff_ms before the second attempt and twice as long before each after.
         *
         * tables maps a table name in the owner connection's search path to its settings:
         *     'receipts' => ['key' => 'uuid7', 'interval' => 'day', 'retention_days' => 7],
         * key is 'uuid7' (an id column of UUIDv7s) or 'timestamp' (a timestamptz column), interval is
         * 'day' or 'month', and retention_days is a whole number or null to keep every partition.
         *
         * The core's own tables are listed here; an application adds its tables next to them. The
         * receipt tables are partitioned by retention class first (PRD 4, 8.4), and each class is
         * managed as its own table: Standard receipts per day, dropped a week after the day ends,
         * and Evidence receipts per month, never dropped here. Idempotency records are partitioned
         * per day on created_at and dropped a week after the day ends, when every record in it has
         * expired (PRD 4, 6.1).
         */
        'partitions' => [
            'runway_days' => 14,
            'lock_timeout_ms' => 2000,
            'attempts' => 3,
            'backoff_ms' => 250,
            'tables' => [
                'receipts_standard' => ['key' => 'uuid7', 'interval' => 'day', 'retention_days' => 7],
                'receipts_evidence' => ['key' => 'uuid7', 'interval' => 'month', 'retention_days' => null],
                'receipt_projections_standard' => ['key' => 'uuid7', 'interval' => 'day', 'retention_days' => 7],
                'receipt_projections_evidence' => ['key' => 'uuid7', 'interval' => 'month', 'retention_days' => null],
                'idempotency_keys' => ['key' => 'timestamp', 'interval' => 'day', 'retention_days' => 7],
            ],
        ],
    ],

    /*
     * cms:doctor (PRD 3.3, 4.2, 13.2). The Postgres checks connect with the connection's settings
     * as the app role; null means the default connection. postgres.lc_messages also reads the
     * owner role on owner_connection; null means cms.database.owner_connection. Postgres and
     * Valkey get connect_timeout_seconds to answer. partition_runway_days is how far ahead every table in
     * database.partitions.tables must have partitions; keep it below runway_days, which maintenance
     * creates. The registry cache must not be older than vendor_manifest, Composer's
     * vendor/composer/installed.json below the base path when null. --dev looks for node_modules in
     * project_path, the base path when null, and wants Node node_minimum or newer.
     */
    'doctor' => [
        'connection' => null,
        'owner_connection' => null,
        'redis_connection' => 'default',
        'connect_timeout_seconds' => 3,
        'partition_runway_days' => 7,
        'vendor_manifest' => null,
        'project_path' => null,
        'node_minimum' => '22.13.0',
    ],
];
