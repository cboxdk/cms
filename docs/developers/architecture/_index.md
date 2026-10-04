---
title: Architecture notes
weight: 31
description: The notes that describe each area of the kernel as it is built, moved here from CLAUDE.md so that agents read the page of the area they change.
---

# Architecture notes

One page per area of the kernel. Each page says where the code lives, what it does and which tests hold it. Read the page of an area before changing it; a task that changes an area updates its page in the same commit. `CLAUDE.md` holds only the rules and the index of these pages.

- [Modules and their boundaries](modules.md): Why the modules stay in packages/<module>/src, the boundaries the Arch suite keeps between them, what the package requires and suggests, and how a first-party module is added.
- [Conventions and static rules](conventions.md): The rules every module follows and the Arch tests and PHPStan rules that hold them: attributes, mixed and arrays, ignores, internals, transactions, ids, contracts, the Clock, ids, raw SQL, hook IO, kernel table writes, the marker gate and strict Eloquent.
- [The command and query pipelines](pipeline.md): The pipeline shapes of the contracts module, the phases of the command pipeline, the commit on Postgres, the wait after commit, the query pipeline, and how actions and their ports are tested.
- [The kernel's commands and queries](commands.md): The entry, placement, release, publish, actor, grant, role and site commands of the kernel, where each lives, what it reads and plans, its writers, locks and events, and the access queries.
- [Hooks and addons](hooks-and-addons.md): The hook contracts and how the pipeline runs them, the addon manifest and what the build holds it to, the panel contributions of addons, and the workbench's fixture addon.
- [Egress](egress.md): The rule that keeps outbound HTTP and every URL-fetching or program-running function in the egress gateway, the names the Arch suite and PHPStan look for, and the gateway contracts and their adapters.
- [Identity, login and sessions](identity.md): The identity contracts of the core, the identity module and its credential store, the login policy, local accounts, sessions, the login contracts, lifecycle signals and provisioning, and breached passwords.
- [Receipts, idempotency and the commit position](receipts-and-idempotency.md): The ReceiptStore and IdempotencyStore contracts, the types they take, the commit position, and both stores on Postgres.
- [The event log and the runner](events.md): The event contract and its payload rule, the events tables, the writer and reader below the horizon, and cms:events:run with its lanes, batches, parking and release.
- [Fragments, the cache index and invalidation](caching.md): The FragmentStore and CdnDriver contracts, the Valkey store with its fences, and the invalidation subscriber that purges on every content event and acknowledges the origin projection.
- [Migrations, tables and partitions](database.md): The migrations and the tables they build, how privileges are narrowed, and the partition manager for tables on time and on a sequence.
- [Access and row level security](access.md): The access tables, the actor context, the access compiler, the delegated chain, the policy functions and what each context reads and writes.
- [Operations, rebuilds and seeding](long-running-work.md): Work that takes more than a few seconds as operations in laravel-operations, the rebuild of a type table, and cms:seed-scale with the scale check.
- [The registry](registry.md): What cms:build compiles into bootstrap/cache/cms, how the cache is written and read, the scanner, the entries and the build errors, and the panel registry.
- [The generators](generators.md): cms:generate from the blueprint schema to the type descriptors, the PHP records and type catalog, the typed query builders, the record DTOs and codecs, the runtime validators, and cms:schema:editor.
- [The JSON contracts](json-contracts.md): The kernel's JSON Schemas and the codecs generated from them, the TypeScript of the contracts, and the schemas and codecs of the kernel's commands and queries.
- [The surfaces](surfaces.md): REST, Inertia, MCP and the CLI, the shared RunExposedCommand, the surface contract tests, and the inspecting commands.
- [Routing and delivery](routing-and-delivery.md): The path.resolve query and the delivery API that serves it from fragments.
- [The doctor, the error catalog and telemetry](doctor-and-errors.md): cms:doctor and its checks, the error catalog every code comes from, and the telemetry contract the pipelines export through.
- [The panel module](panel.md): The panel module and its routes and policy, the panel's login, the generated props of points and pages, active contributions, the panel SDK, the host runtime, the shell, and themes and branding.
- [The gates, the dev image and the test databases](gates.md): How composer check runs in the dev image, test:affected, CI and mutation testing, the selftest, the JS gates, the Pest suites, the gate runner, the test database per checkout, the tool caches, the services and the progress records.
- [The documentation gate](docs-gate.md): Gate 10: composer docs:check, the inventory of extension points, the embed rules, the layout, the screenshots, the generated reference pages, and the sections of docs/.
