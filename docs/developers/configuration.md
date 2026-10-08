---
title: Configuration
weight: 26
description: Every key of the cbox-cms configuration, its default and what it does, and how an application overrides one key at a time.
---

# Configuration

The kernel's settings live under the key `cbox-cms`. The core's defaults are in `packages/core/config/cbox-cms.php`, the identity module adds its own under `cbox-cms.identity` from `packages/identity/config/identity.php`, and the generators add theirs under `cbox-cms.generators` from `packages/generators/config/generators.php`. An application sets only the keys it changes, in its own `config/cbox-cms.php`. The service providers merge their defaults under the application's file key by key, so every key the application leaves out keeps its default, and a table or check the application adds sits next to the core's.

A test that changes a setting sets the single key, such as `cbox-cms.contracts.<contract>`, in Testbench's `defineEnvironment()`, before anything resolves it. Setting a whole array there, such as `cbox-cms.contracts`, drops the other defaults.

## Contracts

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.contracts` | the thirteen entries below | Maps each contract to the class the container builds for it, as a singleton, the first time something resolves the contract. The class must implement the contract. |

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
| `Cbox\Cms\Contracts\Egress\EgressGateway` | `Cbox\Cms\Core\Egress\Adapter\SsrfEgressGateway` |
| `Cbox\Cms\Contracts\Egress\MailGateway` | `Cbox\Cms\Core\Egress\Adapter\LaravelMailGateway` |
| `Cbox\Cms\Contracts\Identity\BreachedPasswords` | `Cbox\Cms\Identity\BreachedPasswords\Adapter\HibpBreachedPasswords`, set by the identity module's provider when the application names none |
| `Cbox\Cms\Contracts\Identity\LocalCredentialStore` | `Cbox\Cms\Identity\CredentialStore\Adapter\PostgresLocalCredentialStore`, set by the identity module's provider when the application names none |

`Cbox\Cms\Contracts\Cdn\CdnDriver` has no default: the real drivers come with full-scale invalidation, and until an application sets `cbox-cms.contracts.Cbox\Cms\Contracts\Cdn\CdnDriver`, resolving it throws `InvalidContractBinding` with the key to set. The invalidation subscriber on the critical lane purges through it, so `cms:events:run` needs one. Tests use the testkit's `FakeCdnDriver`, and the workbench binds it when its environment has `CBOX_CMS_CDN_DRIVER=fake`; see [CDN driver](../addons/contracts/cdn-driver.md).

For example, `'contracts' => [Clock::class => StagingClock::class]` replaces the clock and keeps the other twelve. See [Contracts](../addons/contracts/_index.md).

## Database

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.database.owner_connection` | `pgsql_owner` | The connection of the owner role, which owns the schema and runs the migrations, partition maintenance and `cms:install`. Only the maintenance process has it. A process that serves HTTP or runs queued jobs with this connection configured stops while it boots, and the core schedules `cms:partitions:maintain` only in a process that has it. See [Postgres roles](../security/postgres-roles.md). |
| `cbox-cms.database.partitions.runway_days` | `14` | How many days ahead of now `cms:partitions:maintain` creates partitions. |
| `cbox-cms.database.partitions.runway_partitions` | `2` | How many empty partitions ahead of its sequence's current value `cms:partitions:maintain` keeps for a table with the key `bigint`, from 1 to 100. |
| `cbox-cms.database.partitions.lock_timeout_ms` | `2000` | The `lock_timeout` of every DDL statement of the partition manager, in milliseconds. |
| `cbox-cms.database.partitions.attempts` | `3` | How often a DDL statement is tried when its lock is busy. |
| `cbox-cms.database.partitions.backoff_ms` | `250` | The wait before the second attempt, in milliseconds; each later wait is twice as long. |
| `cbox-cms.database.partitions.tables` | the core's five tables | The partitioned tables in the owner connection's search path, each with `key` (`uuid7` or `timestamp`), `interval` (`day` or `month`) and `retention_days` (a whole number, or `null` to keep every partition), or with `key` `bigint`, `width`, `sequence`, `retention_days` and `retention_column`. See [Partitions](partitions.md). |

## Addons

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.addons.allowed` | `[]` | The installation's allowlist of addons (PRD 13.8): the Composer packages of the addons it has reviewed, such as `['acme/cms-approvals']`. `cms:build` refuses an installed addon whose package is not on it, and a value that is not a list of package names, with [`registry_addon_not_allowed`](../reference/errors.md#registry_addon_not_allowed), so an addon fails at build, never at run time. See [Addon manifest](../addons/manifest.md#the-allowlist). |
| `cbox-cms.addons.publishers` | `[]` | The Ed25519 public keys of each addon's publisher the installation trusts for its panel bundle, by the addon's Composer package (PRD 13.8): `['acme/cms-approvals' => ['<base64 of 32 bytes>']]`, more than one while a key rotates. `cms:build` refuses the addon's bundle with [`registry_panel_bundle_unsigned`](../reference/errors.md#registry_panel_bundle_unsigned) unless its `panel-signature.json` verifies over `panel-manifest.json` with one of them; a bundle of an addon without keys here passes unsigned when `app.env` is `local`, and nowhere else. A value that is not such a map, or a key that is not 44 base64 characters of 32 bytes, fails the build with the same code. See [Panel contributions](../addons/panel/contributions.md#signing-the-bundle). |
| `cbox-cms.addons.service_actors` | `[]` | The service actor of each installed addon, by the namespace its manifest names: `['reviews' => '<actor id>']`. The actor is created when the installation approves the addon's capabilities, and the addon's subscribers run as it, with its own grants, never as the system. A subscriber of an addon without an active service actor does not run: [`addon_service_actor_unavailable`](../reference/errors.md#addon_service_actor_unavailable). A key that is not an addon namespace, or a value that is not a UUIDv7 actor id, fails when the kernel reads it. See [Addon manifest](../addons/manifest.md). |

## Panel contributions

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.panel.contributions` | `[]` | Per panel point id and contribution id, another `priority` (a whole number from 0 to 1000000, the lowest rendered first) or `enabled => false`: `['account.me.sections@1' => ['approvals.badge' => ['priority' => 50]]]`. `cms:build` compiles it into `panel.php`, and refuses a setting that names no contribution of the point, or is not of this form, with [`registry_panel_override_invalid`](../reference/errors.md#registry_panel_override_invalid). |
| `cbox-cms.panel.replacements` | `[]` | Per replaceable point id and key, the contribution id of the replacement that wins the key when several claim it: `['command.form.field@1' => ['acme:stars' => 'acme.stars-input']]`. `cms:build` compiles it; the other replacements of the key stay listed and are not rendered. Without a winner, two claims fail the build with [`registry_panel_replacement_conflict`](../reference/errors.md#registry_panel_replacement_conflict). |
| `cbox-cms.panel.disabled.addons` | `[]` | The activation state (PRD 13.5): the namespaces of the addons whose panel UI is off. The panel reads it at each request, so it takes effect without `cms:build`. |
| `cbox-cms.panel.disabled.contributions` | `[]` | The activation state: the ids of single contributions that are off, read at each request like `addons`. A value that is not a list of contribution ids makes `cms:panel:fills` exit 78. See [Panel contributions](../addons/panel/contributions.md#order-choices-and-the-kill-switch). |

A local application loads an addon's panel UI from its Vite dev server instead of its bundle with the environment variable `CBOX_CMS_PANEL_DEV_ADDONS=<namespace>=<origin>`, pairs joined by commas, never with a setting; it works in the local environment alone. See [The panel module](panel.md#addon-files-and-the-dev-server).
| `cbox-cms.panel.themes` | `[]` | The panel's themes of design tokens, in the order they compose, a later one over an earlier one: `app`, the application's own, and `<namespace>:<name>` of an allowed addon's, such as `['fixtureaddon:brand', 'app']`. A theme it does not name has no effect. `cms:build` composes them, refuses a composition below WCAG 2.2 AA with [`registry_panel_theme_contrast`](../reference/errors.md#registry_panel_theme_contrast) and anything else it cannot use with [`registry_panel_theme_invalid`](../reference/errors.md#registry_panel_theme_invalid), and writes the stylesheet the panel serves. See [Branding and theming the panel](panel-branding.md). |
| `cbox-cms.panel.app_theme` | `null` | The absolute path of the application's own theme JSON, which `themes` selects as `app`, such as `base_path('resources/panel/theme.json')`. |
| `cbox-cms.panel.branding.root` | `null` | The directory every brand file lies inside, by a path relative to it or an absolute one inside it; the application's base path when null. |
| `cbox-cms.panel.branding.name` | `null` | The product name the shell's header, the login page and every document title show, 1 to 60 characters. Null shows Cbox CMS. |
| `cbox-cms.panel.branding.logo` | `null` | The logo of the shell's header, `['light' => <file>, 'dark' => <file>, 'alt' => <text>]`: an SVG or PNG per colour mode and the alternative text a screen reader announces, 1 to 150 characters. |
| `cbox-cms.panel.branding.login` | `null` | The brand image of the login page and the password pages, of the logo's form; the logo when null. |
| `cbox-cms.panel.branding.favicon` | `null` | The favicon, an SVG or PNG. Null leaves the panel without one. A brand that cannot be used shows Cbox CMS, and `cms:doctor` fails with [`doctor_panel_branding_invalid`](../reference/errors.md#doctor_panel_branding_invalid). |

## CLI surface

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.cli.credential` | `null` | The token of the service credential `cms:run` runs writes as, for example `env('CBOX_CMS_CLI_CREDENTIAL')`. The actor of every write through the CLI is this credential's actor, verified like a Bearer token; it never comes from an argument or an option. `null` is no credential, and every write is rejected with [`unauthorized`](../reference/errors.md#unauthorized), exit 77. Any value other than `null` or a non-empty string makes `cms:run` exit 78. See [The CLI surface](../addons/commands.md#the-cli-surface). |

## Sites

`path.resolve` reads `cbox-cms.sites` (PRD 5.9, 8.10 point 7) to map the host of a request to a site and to build canonical URLs, and `cms:sites:sync` registers each configured site the database lacks with its root node and locales (PRD 11.14, see [site commands](../addons/site-commands.md)). A value that is not a map of site handles to an origin, a list of locales and a list of hosts, a locale named twice, or a host configured for two sites, fails when the kernel reads it.

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.sites` | `[]` | The sites that are served, by the handle each has in the `sites` table, such as `'north' => ['origin' => 'https://north.example', 'locales' => ['da', 'en'], 'hosts' => ['www.north.example']]`. `origin` is the scheme and host every canonical URL of the site is built from; `locales` lists the locales the site publishes in, at least one, each once, which `cms:sites:sync` registers it with and never rewrites; `hosts` lists other hosts that resolve to it and defaults to `[]`. The origin's host resolves to the site too, and a host belongs to one site. Only a configured host resolves, and no URL is ever built from the host a request names. |

## Idempotency

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.queries.budgets.anonymous` | `200` | The cost budget of a read without a credential. Every query action states what a query costs, from the rows it may return, how deep it reads and the relations it expands, and the query pipeline rejects a read above its principal's budget with [`query_over_budget`](../reference/errors.md#query_over_budget) before it reads anything. A whole number from `0`; any other value fails when the kernel reads it. |
| `cbox-cms.queries.budgets.actor` | `1000` | The cost budget of a read as an actor, with the same rules. |
| `cbox-cms.idempotency.wait_budget_ms` | `2000` | How long a command waits, in milliseconds, for another call with the same idempotency key to end. When the budget runs out, the command is rejected with [`idempotency_in_flight`](../reference/errors.md#idempotency_in_flight), which the client may retry. It is `0` to `5000`, part of the 5 seconds a command transaction may take, and `0` means do not wait. A value outside that range fails when the kernel reads it. |

## Wait levels

A command waits after its commit for the wait level its envelope asks for (PRD 8.4); see [commands](../addons/commands.md#the-command-pipeline).

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.receipts.wait_budget_ms` | `5000` | How long a committed command waits, in milliseconds of real time, for its wait level, such as `origin`, which the invalidation subscriber reaches once it has purged the server fragments. When the budget runs out, the command returns `committed_wait_timeout`: committed, but not waited out. The wait runs after the transaction has committed, so it holds no lock. It is `0` to `30000`, and `0` means never wait past commit. A value outside that range fails when the kernel reads it. |

## Egress

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.egress.connect_timeout_ms` | `2000` | How long the egress gateway waits for a connection, in milliseconds, before the request fails with [`egress_unavailable`](../reference/errors.md#egress_unavailable). It is `1` to `30000`. |
| `cbox-cms.egress.timeout_ms` | `10000` | How long a request through the egress gateway may take in all, in milliseconds. It is from the connect timeout to `60000`. A value outside either range, or one that is not a whole number, fails when the kernel reads it. |

Which destinations the gateway refuses is the policy of cboxdk/laravel-ssrf, `ssrf` in the configuration, which keeps its own keys; its `enforce` and `pin_dns` must stay on, or the gateway sends nothing. Mail goes through the mail gateway on Laravel's own `mail.default`, `mail.mailers` and `mail.from`, which keep Laravel's keys. See [Egress](../security/egress.md).

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

## Delivery

The delivery API's `GET /v1/resolve` reads `cbox-cms.delivery` (PRD 8.10, 8.12); see [the delivery API](delivery.md). A value that is not a whole number in its range fails when a request is answered.

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.delivery.max_age_seconds` | `300` | The longest an answer is kept as a fragment and by the edge, in seconds, `1` to `86400`. An answer is never kept past the moment a placement's window next changes it. |
| `cbox-cms.delivery.stale_while_revalidate_seconds` | `30` | How long the edge may serve an answer stale while it refetches it, `0` to `86400`. Only an answer whose window never ends gets it; an answer before a removal never does. |
| `cbox-cms.delivery.stale_if_error_seconds` | `3600` | How long the edge may serve an answer stale while the origin fails, `0` to `3600`, with the same rule. |

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
| `cbox-cms.doctor.checks` | `[]` | Classes of checks to run after the core's runtime checks, in this order. See [Doctor checks](../addons/doctor-checks.md#adding-a-check). The identity module puts its four checks, `identity.connection`, `identity.credential_isolation`, `identity.argon2id` and `identity.session_cookie`, in front of the ones the application names. |
| `cbox-cms.doctor.dev_checks` | `[]` | Classes of checks to run with `--dev`, after the core's development checks, in this order. |

The maintenance process declares itself with the environment variable `CBOX_CMS_MAINTENANCE_PROCESS=true`, never with a setting, because the processes may share their configuration. See [cms:doctor](doctor.md#processes-web-queue-and-maintenance).

An invalid doctor setting makes `cms:doctor` run the single check `doctor.config`, which fails with the code `doctor_config_invalid` and names the setting.

## Identity

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.identity.connection` | `pgsql_identity` | The database connection of the identity role, the only role that reaches the credential store of the local accounts in the schema `cms_identity`. It goes to the same database as the default connection with a role of its own. See [Credential store](../security/credential-store.md). |
| `cbox-cms.identity.local.issuer` | `null` | The issuer every login through the local connection names in its verified assertion: an https URL, or an http URL of a loopback host. Null takes the application's URL, `app.url`, without a trailing slash. See [Local accounts](../security/local-accounts.md). |
| `cbox-cms.identity.login.throttle.identifier.attempts` | `5` | The logins that may fail for one email address within its window, 1 to 10000; the next is refused with [`login_rate_limited`](../reference/errors.md#login_rate_limited). The email is counted in lower case, under its SHA-256. See [Sessions](../security/sessions.md#logging-in-with-a-password). |
| `cbox-cms.identity.login.throttle.identifier.window_seconds` | `900` | The window of an email address's count, from its first attempt, 1 to 86400 seconds. |
| `cbox-cms.identity.login.throttle.ip.attempts` | `50` | The logins that may fail from one IP address within its window, 1 to 10000, whatever the emails; the next is refused with [`login_rate_limited`](../reference/errors.md#login_rate_limited). |
| `cbox-cms.identity.login.throttle.ip.window_seconds` | `900` | The window of an IP address's count, from its first attempt, 1 to 86400 seconds. |
| `cbox-cms.identity.password_reset.token_minutes` | `60` | How long a password reset link works, 5 to 1440 minutes. A link sets a password once. See [Local accounts](../security/local-accounts.md#resetting-a-password). |
| `cbox-cms.identity.password_reset.url` | `null` | The address of the panel's reset page, which a reset link points at with the token as its last path segment: an https URL, or an http URL of a loopback host, without a query or a fragment. Null takes `app.url` followed by `/cms/reset-password`; an application that mounts the panel at another prefix sets it. It is never read from a request. |
| `cbox-cms.identity.password_reset.throttle.identifier.attempts` | `3` | The requests for a reset link for one email address within its window, 1 to 10000; a request above it is answered the same and sends nothing. |
| `cbox-cms.identity.password_reset.throttle.identifier.window_seconds` | `3600` | The window of an email address's count of reset requests, from its first request, 1 to 86400 seconds. |
| `cbox-cms.identity.password_reset.throttle.ip.attempts` | `20` | The requests for a reset link from one IP address within its window, 1 to 10000, whatever the emails. |
| `cbox-cms.identity.password_reset.throttle.ip.window_seconds` | `3600` | The window of an IP address's count of reset requests, from its first request, 1 to 86400 seconds. |
| `cbox-cms.identity.passwords.argon2id.memory_kib` | `65536` | The memory, in KiB, of the Argon2id hash of a local account's password: 1024 to 4194304. A login whose hash was made with other parameters is hashed again with these. |
| `cbox-cms.identity.passwords.argon2id.time` | `4` | The passes of the Argon2id hash of a local account's password: 1 to 64. The hash always uses one thread. |
| `cbox-cms.identity.policy.authoritative_connections` | `[]` | The federated connections marked authoritative: their identity provider owns the access of the actors linked to them, so such an actor has no local login (invariant 38). Never `local`. See [Login policy](../security/login-policy.md). |
| `cbox-cms.identity.policy.staff.connections.local` | `true` | Whether members of staff may log in through the local connection, the local accounts of the identity module. Add a federated connection by its name, such as `cbox-cms.identity.policy.staff.connections.entra`, set to `true`. |
| `cbox-cms.identity.policy.staff.methods.password` | `true` | Whether members of staff may log in with the method `password`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.staff.methods.passkey` | `true` | Whether members of staff may log in with the method `passkey`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.staff.methods.magic_link` | `false` | Whether members of staff may log in with the method `magic_link`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.staff.methods.social` | `false` | Whether members of staff may log in with the method `social`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.staff.methods.invitation` | `true` | Whether members of staff may log in with the method `invitation`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.staff.methods.password_reset` | `true` | Whether members of staff may log in with the method `password_reset`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.staff.methods.federated` | `true` | Whether members of staff may log in with the method `federated`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.staff.local_login` | `true` | Whether local login is switched on for members of staff. Off, only federated connections give them a session ([`login_local_disabled`](../reference/errors.md#login_local_disabled)). |
| `cbox-cms.identity.policy.staff.local_factors` | `passkey_or_two_factors` | The factors a local login of members of staff needs: `password`, or `passkey_or_two_factors`, a passkey or two factors in the amr claim ([`login_factors_unavailable`](../reference/errors.md#login_factors_unavailable)). Only `local` and `testing` may set `password`; any other environment refuses it ([`login_policy_invalid`](../reference/errors.md#login_policy_invalid)). The workbench sets `password`, because B1 part 1 offers no passkey or second factor. |
| `cbox-cms.identity.policy.staff.federated_amr` | `[]` | amr values of which a federated login of members of staff must show one, such as `mfa`. Empty with `federated_acr` empty requires none. |
| `cbox-cms.identity.policy.staff.federated_acr` | `[]` | acr values of which a federated login of members of staff may show one instead of an amr value. |
| `cbox-cms.identity.policy.staff.inactivity_minutes` | `60` | Minutes without a request after which a session of members of staff ends. |
| `cbox-cms.identity.policy.staff.absolute_minutes` | `720` | Minutes after the login after which a session of members of staff ends, whatever happens; at least `inactivity_minutes`. |
| `cbox-cms.identity.policy.end_user.connections.local` | `true` | Whether end users may log in through the local connection, the local accounts of the identity module. Add a federated connection by its name, such as `cbox-cms.identity.policy.end_user.connections.entra`, set to `true`. |
| `cbox-cms.identity.policy.end_user.methods.password` | `true` | Whether end users may log in with the method `password`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.end_user.methods.passkey` | `true` | Whether end users may log in with the method `passkey`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.end_user.methods.magic_link` | `true` | Whether end users may log in with the method `magic_link`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.end_user.methods.social` | `true` | Whether end users may log in with the method `social`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.end_user.methods.invitation` | `true` | Whether end users may log in with the method `invitation`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.end_user.methods.password_reset` | `true` | Whether end users may log in with the method `password_reset`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.end_user.methods.federated` | `true` | Whether end users may log in with the method `federated`. A method the map does not name is not allowed. |
| `cbox-cms.identity.policy.end_user.local_login` | `true` | Whether local login is switched on for end users. Off, only federated connections give them a session ([`login_local_disabled`](../reference/errors.md#login_local_disabled)). |
| `cbox-cms.identity.policy.end_user.local_factors` | `password` | The factors a local login of end users needs: `password`, or `passkey_or_two_factors`, a passkey or two factors in the amr claim ([`login_factors_unavailable`](../reference/errors.md#login_factors_unavailable)). |
| `cbox-cms.identity.policy.end_user.federated_amr` | `[]` | amr values of which a federated login of end users must show one, such as `mfa`. Empty with `federated_acr` empty requires none. |
| `cbox-cms.identity.policy.end_user.federated_acr` | `[]` | acr values of which a federated login of end users may show one instead of an amr value. |
| `cbox-cms.identity.policy.end_user.inactivity_minutes` | `43200` (30 days) | Minutes without a request after which a session of end users ends. |
| `cbox-cms.identity.policy.end_user.absolute_minutes` | `129600` (90 days) | Minutes after the login after which a session of end users ends, whatever happens; at least `inactivity_minutes`. |
| `cbox-cms.identity.session.cookie.production.name` | `__Host-cms_session` | The name of the session cookie in production, and in every environment the map does not name. Outside local and testing it must start with `__Host-`, or a process that serves HTTP refuses to boot ([`session_cookie_insecure`](../reference/errors.md#session_cookie_insecure)). See [Sessions](../security/sessions.md). |
| `cbox-cms.identity.session.cookie.production.secure` | `true` | Whether the session cookie in production is Secure. Outside local and testing it must be. |
| `cbox-cms.identity.session.cookie.production.same_site` | `lax` | The SameSite of the session cookie in production: `lax`, `strict` or `none`. Outside local and testing it must not be `none`. |
| `cbox-cms.identity.session.cookie.local.name` | `cms_session` | The name of the session cookie in the environment `local`, which the workbench serves over plain HTTP. |
| `cbox-cms.identity.session.cookie.local.secure` | `false` | Whether the session cookie in `local` is Secure. |
| `cbox-cms.identity.session.cookie.local.same_site` | `lax` | The SameSite of the session cookie in `local`. |
| `cbox-cms.identity.session.cookie.testing.name` | `cms_session` | The name of the session cookie in the environment `testing`, which the browser tests serve over plain HTTP. |
| `cbox-cms.identity.session.cookie.testing.secure` | `false` | Whether the session cookie in `testing` is Secure. |
| `cbox-cms.identity.session.cookie.testing.same_site` | `lax` | The SameSite of the session cookie in `testing`. |

The login policy is read the first time a login asks it; a policy out of form throws `InvalidLoginPolicy` with [`login_policy_invalid`](../reference/errors.md#login_policy_invalid), naming the key.

## The installation operator

The installation operator, the service actor the maintenance commands run as, has no key. `cms:install` creates it once, and the kernel keeps its id in the table `installation`, never in `.env` or the configuration, so every process and every deploy of an installation finds the same one, and no deploy can name another. The operator is created on `cbox-cms.database.owner_connection`, in the maintenance process. See [Maintenance commands](maintenance-commands.md).

## Access

`cms:access:bootstrap` reads `cbox-cms.access` (PRD 5.10); see [Maintenance commands](maintenance-commands.md#the-access-bootstrap). A handle out of form fails when the command starts, with exit 78.

| Key | Default | What it does |
|---|---|---|
| `cbox-cms.access.bootstrap_role` | `administrator` | The handle of the bootstrap role: a lowercase letter and up to 62 lowercase letters, digits and underscores. The bootstrap creates the role with every command and query of the registry and the ceiling sensitive, or uses the role with the handle when one exists with that ceiling and every one of them. |

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
