<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\IdGenerator;
use Cbox\Cms\Contracts\ReceiptStore;
use Cbox\Cms\Core\Clock\Adapter\SystemClock;
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
         * and Evidence receipts per month, never dropped here.
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
            ],
        ],
    ],
];
