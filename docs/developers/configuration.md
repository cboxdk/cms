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
| `cbox-cms.contracts` | the nine entries below | Maps each contract to the class the container builds for it, as a singleton, the first time something resolves the contract. The class must implement the contract. |

| Contract | Default class |
|---|---|
| `Cbox\Cms\Contracts\Clock` | `Cbox\Cms\Core\Clock\Adapter\SystemClock` |
| `Cbox\Cms\Contracts\IdGenerator` | `Cbox\Cms\Core\Ids\Adapter\SystemIdGenerator` |
| `Cbox\Cms\Contracts\ReceiptStore` | `Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore` |
| `Cbox\Cms\Contracts\IdempotencyStore` | `Cbox\Cms\Core\IdempotencyStore\Adapter\PostgresIdempotencyStore` |
| `Cbox\Cms\Contracts\Identity\ActorDirectory` | `Cbox\Cms\Core\Identity\Adapter\PostgresActorDirectory` |
| `Cbox\Cms\Contracts\Identity\CredentialVerifier` | `Cbox\Cms\Core\Identity\Adapter\PostgresCredentialVerifier` |
| `Cbox\Cms\Contracts\Cache\FragmentStore` | `Cbox\Cms\Core\Cache\Adapter\ValkeyFragmentStore` |
| `Cbox\Cms\Contracts\TypeTables\TypeTableReader` | `Cbox\Cms\Core\TypeTables\Adapter\PostgresTypeTableReader` |
| `Cbox\Cms\Contracts\Telemetry\Telemetry` | `Cbox\Cms\Core\Telemetry\Adapter\LogTelemetry` |

`Cbox\Cms\Contracts\Cdn\CdnDriver` has no default: the real drivers come with full-scale invalidation, and until an application sets `cbox-cms.contracts.Cbox\Cms\Contracts\Cdn\CdnDriver`, resolving it throws `InvalidContractBinding` with the key to set. The invalidation subscriber on the critical lane purges through it, so `cms:events:run` needs one. Tests use the testkit's `FakeCdnDriver`, and the workbench binds it when its environment has `CBOX_CMS_CDN_DRIVER=fake`; see [CDN driver](../addons/contracts/cdn-driver.md).

For example, `'contracts' => [Clock::class => StagingClock::class]` replaces the clock and keeps the other eight. See [Contracts](../addons/contracts/_index.md).

## Database

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.database.owner_connection` | `pgsql_owner` | The connection of the owner role, which owns the schema and runs the migrations and partition maintenance. Only the maintenance process has it. A process that serves HTTP or runs queued jobs with this connection configured stops while it boots, and the core schedules `cms:partitions:maintain` only in a process that has it. See [Postgres roles](../security/postgres-roles.md). |
| `cbox-cms.database.partitions.runway_days` | `14` | How many days ahead of now `cms:partitions:maintain` creates partitions. |
| `cbox-cms.database.partitions.runway_partitions` | `2` | How many empty partitions ahead of its sequence's current value `cms:partitions:maintain` keeps for a table with the key `bigint`, from 1 to 100. |
| `cbox-cms.database.partitions.lock_timeout_ms` | `2000` | The `lock_timeout` of every DDL statement of the partition manager, in milliseconds. |
| `cbox-cms.database.partitions.attempts` | `3` | How often a DDL statement is tried when its lock is busy. |
| `cbox-cms.database.partitions.backoff_ms` | `250` | The wait before the second attempt, in milliseconds; each later wait is twice as long. |
| `cbox-cms.database.partitions.tables` | the core's five tables | The partitioned tables in the owner connection's search path, each with `key` (`uuid7` or `timestamp`), `interval` (`day` or `month`) and `retention_days` (a whole number, or `null` to keep every partition), or with `key` `bigint`, `width`, `sequence`, `retention_days` and `retention_column`. See [Partitions](partitions.md). |

## Addons

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.addons.service_actors` | `[]` | The service actor of each installed addon, by the namespace its manifest names: `['reviews' => '<actor id>']`. The actor is created when the installation approves the addon's capabilities, and the addon's subscribers run as it, with its own grants, never as the system. A subscriber of an addon without an active service actor does not run: [`addon_service_actor_unavailable`](../reference/errors.md#addon_service_actor_unavailable). A key that is not an addon namespace, or a value that is not a UUIDv7 actor id, fails when the kernel reads it. See [Addon manifest](../addons/manifest.md). |

## CLI surface

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.cli.credential` | `null` | The token of the service credential `cms:run` runs writes as, for example `env('CBOX_CMS_CLI_CREDENTIAL')`. The actor of every write through the CLI is this credential's actor, verified like a Bearer token; it never comes from an argument or an option. `null` is no credential, and every write is rejected with [`unauthorized`](../reference/errors.md#unauthorized), exit 77. Any value other than `null` or a non-empty string makes `cms:run` exit 78. See [The CLI surface](../addons/commands.md#the-cli-surface). |

## Sites

`path.resolve` reads `cbox-cms.sites` (PRD 5.9, 8.10 point 7) to map the host of a request to a site and to build canonical URLs. A value that is not a map of site handles to an origin and a list of hosts, or a host configured for two sites, fails when the kernel reads it.

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.sites` | `[]` | The sites that are served, by the handle each has in the `sites` table, such as `'north' => ['origin' => 'https://north.example', 'hosts' => ['www.north.example']]`. `origin` is the scheme and host every canonical URL of the site is built from; `hosts` lists other hosts that resolve to it and defaults to `[]`. The origin's host resolves to the site too, and a host belongs to one site. Only a configured host resolves, and no URL is ever built from the host a request names. |

## Idempotency

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.queries.budgets.anonymous` | `200` | The cost budget of a read without a credential. Every query action states what a query costs, from the rows it may return, how deep it reads and the relations it expands, and the query pipeline rejects a read above its principal's budget with [`query_over_budget`](../reference/errors.md#query_over_budget) before it reads anything. A whole number from `0`; any other value fails when the kernel reads it. |
| `cbox-cms.queries.budgets.actor` | `1000` | The cost budget of a read as an actor, with the same rules. |
| `cbox-cms.idempotency.wait_budget_ms` | `2000` | How long a command waits, in milliseconds, for another call with the same idempotency key to end. When the budget runs out, the command is rejected with [`idempotency_in_flight`](../reference/errors.md#idempotency_in_flight), which the client may retry. It is `0` to `5000`, part of the 5 seconds a command transaction may take, and `0` means do not wait. A value outside that range fails when the kernel reads it. |

## Event runner

`cms:events:run` reads `cbox-cms.events.runner` (PRD 7.4 to 7.8); see [subscribers](../addons/subscribers.md#the-runner). A value outside its range fails when the runner starts, with exit 64.

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.events.runner.service_actor` | `null` | The UUIDv7 of the service actor the subscribers run as. It must exist, be of class service and be active, or the runner refuses to run. |
| `cbox-cms.events.runner.batch_size` | `100` | The most events one batch reads, `1` to `10000`. |
| `cbox-cms.events.runner.batch_budget_ms` | `1000` | How long a batch hands events to its subscriber before it commits, `1` to `1900`, so its transaction stays under 2 seconds. |
| `cbox-cms.events.runner.max_attempts` | `5` | The tries of an event, `1` to `100`, before its aggregate is parked for the subscription. |
| `cbox-cms.events.runner.backoff_base_ms` | `100` | The wait after the first failed try, doubled after each further one. |
| `cbox-cms.events.runner.backoff_max_ms` | `5000` | The longest wait between tries, at least `backoff_base_ms` and at most `60000`. |
| `cbox-cms.events.runner.idle_sleep_ms` | `200` | The wait when no subscription of the lane had anything to do. |

## Seeding

`cms:seed-scale` reads `cbox-cms.seeding` (GUARDRAILS 4.3); see [seeding](seeding.md). A value that is neither null nor a UUIDv7 fails when the command starts, with exit 78.

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.seeding.service_actor` | `null` | The UUIDv7 of the service actor the seeder writes as. It must exist, be of class service and be active, or the seeder refuses to run. Its grants decide where the entries go and which fields it writes. |

## Fragments

The invalidation subscriber, `fragments.invalidate` on the critical lane, reads `cbox-cms.fragments` (PRD 8.12 point 1); see [subscribers](../addons/subscribers.md#the-kernels-invalidation-subscriber). A value that is not a whole number from `1` to `86400` fails when the runner builds the subscriber.

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.fragments.fence_seconds` | `60` | How long the fence of a purge lives, in seconds. While it lives, the fragment store refuses a fragment of the purged key that a read built at or below the purge's commit position, because that read may not have seen the change. Keep it above the slowest fragment build and the lag of a read replica. |

## Rebuild

`cms:types:rebuild` reads `cbox-cms.rebuild` (PRD 4.1, invariant 22); see [operations](operations.md#rebuilding-a-types-read-model). A value outside its range fails when the command starts, with exit 64.

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.rebuild.service_actor` | `null` | The UUIDv7 of the service actor a rebuild runs as. It must exist and be of class service, or the rebuild is refused with [`rebuild_identity_invalid`](../reference/errors.md#rebuild_identity_invalid), and be active, or with [`actor_not_active`](../reference/errors.md#actor_not_active). Row level security holds for it, so it rebuilds the entries its grants reach. |
| `cbox-cms.rebuild.chunk_size` | `100` | The most entries one chunk rebuilds in its transaction, `1` to `1000`. Postgres ends a chunk's transaction after 2 seconds; lower it when a chunk comes near that. |

## Doctor

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.doctor.connection` | `null` | The connection the Postgres checks use, as the app role. `null` is the default connection. |
| `cbox-cms.doctor.owner_connection` | `null` | The owner connection the doctor looks for. `null` is `cbox-cms.database.owner_connection`. |
| `cbox-cms.doctor.owner_role` | `null` | The owner role whose `lc_messages` `postgres.lc_messages` reads from the catalog. `null` is the username of the owner connection when this process has it. The doctor never logs in as the owner. |
| `cbox-cms.doctor.redis_connection` | `default` | The Redis connection `valkey.reachable` pings. |
| `cbox-cms.doctor.connect_timeout_seconds` | `3` | How long Postgres and Valkey get to answer. |
| `cbox-cms.doctor.partition_runway_days` | `7` | How far ahead every partitioned table must have partitions for `partitions.runway` to pass. Keep it below `cbox-cms.database.partitions.runway_days`. |
| `cbox-cms.doctor.partition_runway_partitions` | `1` | How many empty partitions ahead of its sequence every table with the key `bigint` must have for `partitions.runway` to pass. Keep it below `cbox-cms.database.partitions.runway_partitions`. |
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
| `cbox-cms.generators.migrations_directory` | `database/migrations/cms` | Where `cms:generate` writes the migrations of the type tables and the schema lock of each table they are computed from. The generated service provider registers the directory with the migrator. |

`cms:generate` owns `php_directory`, `typescript_directory` and `migrations_directory`: it removes every file there that it did not generate. The first two must therefore end in a directory named `Generated` or `generated`, and `migrations_directory` in `migrations/cms`. An invalid setting makes `cms:generate` exit 78.

A test holds this page to the configuration files: every key of both files is in a table here.
