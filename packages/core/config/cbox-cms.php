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
     * its own config/cbox-cms.php; the entries it leaves out keep these defaults. The class is resolved
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
         * partition manager refuses to run on it. Only the maintenance process, which runs the
         * migrations and cms:partitions:maintain and serves no HTTP, gets this connection and the
         * owner's credentials; the web and queue processes do not, so code in them cannot reach
         * DDL or pass the row level security as the owner. The core refuses to boot a process
         * that serves HTTP or runs queued jobs with this connection configured, so the web and
         * queue processes must not share a configuration cache with the maintenance process. The
         * core schedules cms:partitions:maintain only in a process where this connection is
         * configured.
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
     * Idempotency keys (PRD 6.1). A command whose key another call still holds waits at most
     * wait_budget_ms for that call to end, then it is rejected with idempotency_in_flight, which
     * the client may retry. It is 0 to 5000 ms, part of the 5 seconds a command transaction may
     * take (GUARDRAILS 4.1), and 0 means do not wait.
     */
    'idempotency' => [
        'wait_budget_ms' => 2000,
    ],

    /*
     * cms:doctor (PRD 3.3, 4.2, 13.2). The Postgres checks connect with the connection's settings
     * as the app role; null means the default connection. The doctor never logs in as the owner
     * role: postgres.lc_messages reads the lc_messages of the role owner_role names from the
     * catalog; null means the username of owner_connection when that connection is configured in
     * this process, and owner_connection null means cbox-cms.database.owner_connection.
     * postgres.owner_credentials fails when owner_connection is configured in a process whose
     * environment does not declare it the maintenance process with CBOX_CMS_MAINTENANCE_PROCESS=true,
     * or that serves HTTP or runs queued jobs. That declaration is an environment variable of the
     * maintenance process alone, never a setting here, which every process may share. Postgres and
     * Valkey get connect_timeout_seconds to answer. partition_runway_days is how far ahead every table in
     * database.partitions.tables must have partitions; keep it below runway_days, which maintenance
     * creates. The registry cache must not be older than vendor_manifest, Composer's
     * vendor/composer/installed.json below the base path when null. --dev looks for node_modules in
     * project_path, the base path when null, and wants Node node_minimum or newer.
     *
     * An application or addon adds its own checks by class name: those in checks run after the
     * core's runtime checks, and those in dev_checks run with --dev after the core's development
     * checks, each list in its order. Each class implements Cbox\Cms\Contracts\Doctor\DoctorCheck
     * and is built by the container. Every id must be unique, and a check may require only checks
     * that run before it, the core's included. A class that cannot be used fails doctor.config.
     */
    'doctor' => [
        'connection' => null,
        'owner_connection' => null,
        'owner_role' => null,
        'redis_connection' => 'default',
        'connect_timeout_seconds' => 3,
        'partition_runway_days' => 7,
        'vendor_manifest' => null,
        'project_path' => null,
        'node_minimum' => '22.13.0',
        'checks' => [],
        'dev_checks' => [],
    ],
];
