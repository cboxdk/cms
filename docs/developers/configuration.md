---
title: Configuration
weight: 26
description: Every key of the cbox-cms configuration, its default and what it does, and how an application overrides one key at a time.
---

# Configuration

The kernel's settings live under the key `cbox-cms`. The core's defaults are in `packages/core/config/cbox-cms.php`, and the generators add theirs under `cbox-cms.generators` from `packages/generators/config/generators.php`. An application sets only the keys it changes, in its own `config/cbox-cms.php`. The service providers merge their defaults under the application's file key by key, so every key the application leaves out keeps its default, and a table or check the application adds sits next to the core's.

A test that changes a setting sets the single key, such as `cbox-cms.contracts.<contract>`, in Testbench's `defineEnvironment()`, before anything resolves it. Setting a whole array there, such as `cbox-cms.contracts`, drops the other defaults.

## Contracts

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.contracts` | the four entries below | Maps each contract to the class the container builds for it, as a singleton, the first time something resolves the contract. The class must implement the contract. |

| Contract | Default class |
|---|---|
| `Cbox\Cms\Contracts\Clock` | `Cbox\Cms\Core\Clock\Adapter\SystemClock` |
| `Cbox\Cms\Contracts\IdGenerator` | `Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator` |
| `Cbox\Cms\Contracts\ReceiptStore` | `Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore` |
| `Cbox\Cms\Contracts\IdempotencyStore` | `Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore` |

For example, `'contracts' => [Clock::class => StagingClock::class]` replaces the clock and keeps the other three. See [Contracts](../addons/contracts/_index.md).

## Database

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.database.owner_connection` | `pgsql_owner` | The connection of the owner role, which owns the schema and runs the migrations and partition maintenance. Only the maintenance process has it. A process that serves HTTP or runs queued jobs with this connection configured stops while it boots, and the core schedules `cms:partitions:maintain` only in a process that has it. See [Postgres roles](../security/postgres-roles.md). |
| `cbox-cms.database.partitions.runway_days` | `14` | How many days ahead of now `cms:partitions:maintain` creates partitions. |
| `cbox-cms.database.partitions.lock_timeout_ms` | `2000` | The `lock_timeout` of every DDL statement of the partition manager, in milliseconds. |
| `cbox-cms.database.partitions.attempts` | `3` | How often a DDL statement is tried when its lock is busy. |
| `cbox-cms.database.partitions.backoff_ms` | `250` | The wait before the second attempt, in milliseconds; each later wait is twice as long. |
| `cbox-cms.database.partitions.tables` | the core's five tables | The partitioned tables in the owner connection's search path, each with `key` (`uuid7` or `timestamp`), `interval` (`day` or `month`) and `retention_days` (a whole number, or `null` to keep every partition). See [Partitions](partitions.md). |

## Doctor

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.doctor.connection` | `null` | The connection the Postgres checks use, as the app role. `null` is the default connection. |
| `cbox-cms.doctor.owner_connection` | `null` | The owner connection the doctor looks for. `null` is `cbox-cms.database.owner_connection`. |
| `cbox-cms.doctor.owner_role` | `null` | The owner role whose `lc_messages` `postgres.lc_messages` reads from the catalog. `null` is the username of the owner connection when this process has it. The doctor never logs in as the owner. |
| `cbox-cms.doctor.redis_connection` | `default` | The Redis connection `valkey.reachable` pings. |
| `cbox-cms.doctor.connect_timeout_seconds` | `3` | How long Postgres and Valkey get to answer. |
| `cbox-cms.doctor.partition_runway_days` | `7` | How far ahead every partitioned table must have partitions for `partitions.runway` to pass. Keep it below `cbox-cms.database.partitions.runway_days`. |
| `cbox-cms.doctor.vendor_manifest` | `null` | The file the registry cache must not be older than. `null` is `vendor/composer/installed.json` below the base path. |
| `cbox-cms.doctor.project_path` | `null` | Where `--dev` looks for `node_modules`. `null` is the base path. |
| `cbox-cms.doctor.node_minimum` | `22.13.0` | The oldest Node `dev.node` accepts. |
| `cbox-cms.doctor.checks` | `[]` | Classes of checks to run after the core's runtime checks, in this order. See [Doctor checks](../addons/doctor-checks.md#adding-a-check). |
| `cbox-cms.doctor.dev_checks` | `[]` | Classes of checks to run with `--dev`, after the core's development checks, in this order. |

The maintenance process declares itself with the environment variable `CBOX_CMS_MAINTENANCE_PROCESS=true`, never with a setting, because the processes may share their configuration. See [cms:doctor](doctor.md#processes-web-queue-and-maintenance).

An invalid doctor setting makes `cms:doctor` run the single check `doctor.config`, which fails with the code `doctor_config_invalid` and names the setting.

## Generators

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.generators.root` | `null` | The directory the other paths are relative to. `null` is the application's base path. |
| `cbox-cms.generators.roots` | `['app' => 'schema']` | The schema roots: each owner and the directory of its blueprint files. Every `*.yaml` below a directory is a blueprint file of that owner. The application's own types are owner `app`. |
| `cbox-cms.generators.php_directory` | `app/Cms/Generated` | Where `cms:generate` writes the PHP code. |
| `cbox-cms.generators.php_namespace` | `App\Cms\Generated` | The namespace of the PHP code. |
| `cbox-cms.generators.typescript_directory` | `resources/js/cms/generated` | Where `cms:generate` writes the TypeScript. |

`cms:generate` owns `php_directory` and `typescript_directory`: it removes every file there that it did not generate. Both must therefore end in a directory named `Generated` or `generated`. An invalid setting makes `cms:generate` exit 78.

A test holds this page to the configuration files: every key of both files is in a table here.
