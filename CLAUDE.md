# laravel-cms

The repository of the one Composer package `cboxdk/cms` (GUARDRAILS 2.6, PRD 2.32), with the kernel's modules in `packages/<module>` (see "Modules" under "Hvor ting bor"), the JS workspaces in `js/` and, as they come, the sidecars. The remote is `origin`, github.com/cboxdk/cms.

This file is short on purpose: every agent loads it on every turn. The architecture notes it used to hold are pages below `docs/developers/architecture/`, listed under "Architecture notes". Read the page of an area before changing that area, and when a task changes an area, update its page in the same commit, not this file.

## Sources of truth

These live in the planning repo and are read-only unless a rule below says otherwise:

- `/Users/sylvester/Projects/Cbox/cms-planning/PRD.md`: what is built (Danish).
- `/Users/sylvester/Projects/Cbox/cms-planning/GUARDRAILS.md`: how code is written. Every rule applies here.
- `/Users/sylvester/Projects/Cbox/cms-planning/MILESTONES.md`: the build order and exit criteria.
- `PROGRESS.md` in this repo: the working state. Read it first in every session and every agent task that changes code.

## Hard rules

- Push to `origin` (github.com/cboxdk/cms) is allowed; never force-push, never rewrite pushed history, never publish packages or tag releases without Sylvester.
- Never change the cboxdk ecosystem repos (laravel-id, laravel-telemetry and the others); use them as released. Lifting them is a separate track (GUARDRAILS 1.7). If a package lacks something, record it in `PROGRESS.md`.
- One commit per task, message `<block>-<task>: <what>`, for example `M1-T3: command envelope and idempotency store`.
- Follow GUARDRAILS 7.3: never weaken a check that verifies your own change (analysis config, test filters, snapshots, architecture tests, CI). If you believe a check is wrong, leave it, and add the case under "Til review af Sylvester" in `PROGRESS.md`.
- A bug fix has a regression test that fails before the fix.
- Everything committed is production-ready (GUARDRAILS 11). No placeholders: no temporary or provisional formats, no hard-coded or "not implemented" bodies, no TODO/FIXME/XXX, no skipped tests, no gates reported as not run because work is missing. If something cannot be finished in its block, do not build it; record it in `PROGRESS.md`. Fakes for contracts are not placeholders.
- The kernel knows no content types (GUARDRAILS 2.4). Every type, field, block type and capability is defined in schema files (Data Studio, a template or starter, a module or addon's own types, or `workbench/` fixtures). The kernel's modules (contracts, core, testkit, generators, http, cli, mcp, identity, panel) never name a type, field or route and never assume one such as `page` or `article` exists; everything works for any type from its schema.
- PHP 8.5, Laravel 13 only, PHPStan level 10 without baseline, Pest 5, Rector, Pint, React 19 with strict TypeScript.
- Run `composer check` before saying a task is done: the local profile of GUARDRAILS 10, gates 1 to 6 in order (Pint and Prettier, Rector, PHPStan, tsc and ESLint, Vitest and the Pest suites, `check:generated`), every gate also after a failure, each marked pass, fail or not run, exit 1 when a gate fails; gates 7 to 11 show as not in the local profile. Start the services first with `composer services:up`; with Postgres or Valkey down, gate 5 fails and is never skipped. `-- --report=<file>` writes a JSON report, `--brief` leaves failed output out of the console, `--gate=<n>` runs only those gates. Report the gates you ran and any that did not run.
- The gates run in the dev image `ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1`, locally as in CI: `composer check` on the host runs itself again in a container of the image for the current checkout, a worktree included, on the network of the main checkout's services, which it never starts. `composer image:run -- <command>` runs any command the same way; `composer test:affected [-- <pest arguments>]` is fast feedback, not a gate; `composer image:prune` removes the volumes of checkouts that are gone. Details on `docs/developers/architecture/gates.md`.
- CI is `bin/ci`: `.github/workflows/ci.yml` runs it on every pull request, and `docker compose -f compose.ci.yaml run --rm ci` runs it locally on a clean git archive of HEAD (commit first). It runs `composer check -- --pr`: gates 1 to 6, gate 7 (the kit's Storybook), 8 (the Browser suite), 9 (`composer audit` and `npm audit`) and 10 (`composer docs:check`); gate 11 is reported as not run until Sylvester sets branch protection on main. The two slow suites run in four parallel shard jobs, so each part keeps the 15-minute budget (`Check\Domain\ShardPlan`): the gates job runs `composer check -- --pr --only=gates` and reports the Postgres and Browser suites as run in the shards, never as not run, each shard job runs `composer check -- --pr --shard=<i>/4 --shard-report=<file>`, and the verdict job runs `composer shards:verdict`, which fails unless the gates and every shard of the plan passed and each shard reported once. Add shards or parallelise; never drop a gate. Mutation testing is deferred until after v1 (Sylvester, 2 October 2026) and runs only on demand (`--mutation`, `CMS_CI_MUTATION=1`, or the workflow input `mutation`); it needs `CMS_CI_BASE_REF`, the base of the change, derived from the checkout when unset. Run the containerized run after changing `bin/ci`, the CI files or the environment the gates need, and record its wall time in `PROGRESS.md` against the 15-minute budget of GUARDRAILS 10.
- Run `composer check:selftest` after changing a gate, the tool configuration or the check itself: it plants one known violation per gate in a worktree of HEAD and passes only when each gate catches it. Commit first; it takes about four minutes.
- Run `composer test-db:prune` after removing a checkout or a worktree by hand; `composer test-db:prune -- --dry-run` drops nothing. Composer passes options to it only after `--`.
- Every changed, removed or new check and test is recorded with its reason in `CHECKS-LOG.md` at the repo root (GUARDRAILS 7.3, version 1.9), in an entry under the heading of its block (`## M0`, `## M1`, ...) that starts with the task (`M1-T3:`) and says GUARDRAILS 7.3. "Til review af Sylvester" in `PROGRESS.md` holds only open decisions for Sylvester; notes without a decision go under "Info" there.
- `composer progress:check -- <block>-<task> [--changed-checks] [--range=main..HEAD]` fails unless the task is recorded: an entry naming it under "Kontroller kørt" in `PROGRESS.md` with the gates it ran, with `--changed-checks` also its entry in `CHECKS-LOG.md` under its block that says GUARDRAILS 7.3, and with `--range` no empty commit. The merge queue of `.claude/workflows/cms-milestone.js` writes each task's entries in a commit `<block>-<task>: progress` and runs it before it moves main. `composer progress:test` runs the tests that read `PROGRESS.md`, `CHECKS-LOG.md` and the review commits (all in `tests/Feature/Tooling/Progress`); every agent that commits entries runs it on that commit.
- A review fix committed straight on main, message `<block>-review: ...`, adds its own entries in the same commit: `<block>-review` under "Kontroller kørt" in `PROGRESS.md` with the gates it ran, and, when it changes or removes a check file (as `Progress\Domain\CheckPaths` lists them: tests, the testkit, `examples/`, `tools/`, `js/tooling/`, `.github/`, `docker/`, the tool configuration, `bin/ci`, the compose files), `<block>-review` in `CHECKS-LOG.md` under its block saying GUARDRAILS 7.3. `ProgressCheckTest` holds every such commit in the history to it; one that missed them is recorded afterwards only by entries that name its hash.
- The JS gates run from the root after `npm ci`: `npm run typecheck`, `npm run lint` and `npm run format:check`, configured in the `js/tooling` workspace. The panel is `js/panel`, its components `js/ui-kit`, the SDK `js/panel-sdk`. The kit's tokens have one source, `js/ui-kit/tokens.json`; `npm run generate:tokens` writes the generated files, never edited by hand. Vitest is the JS unit suite (`npm run test:js`, a step of gate 5); the kit's Storybook is gate 7 and runs only in the dev image.
- The Pest suites of gate 5 are `Unit`, `Codecs`, `Contract`, `Postgres`, `Arch` and `Actions`; a module puts tests for a suite in `packages/<module>/tests/<Suite>`, its other tests are `Unit`. `Browser` is gate 8 (`composer panel:build`, then `composer image:run -- vendor/bin/pest --testsuite=Browser`); `Mutation` runs on demand in the image. The Postgres and Browser suites need `composer services:up` and run on the testkit's `RealPostgres` and `RealValkey` harnesses in this checkout's own test database (a parallel worker in its own); a nested transaction fails a Postgres test.
- `cms:doctor` checks the shared dev database `cms`. Prepare it with `composer services:up`, then `composer dev:prepare` from the main checkout, never from a worktree, each step in the dev image: the workbench's `APP_KEY`, the migrations as the owner role, `cms:partitions:maintain`, `cms:build`, `cms:install`, `cms:sites:sync` and `composer panel:build`, each idempotent, stopping at a failing step with its exit code; `composer workbench:serve` then serves the panel at `http://127.0.0.1:8080/cms` (`docs/getting-started/first-login.md`). In the php container the doctor needs no flag; on a host with `allow_url_fopen = On`, run `php -d allow_url_fopen=0 vendor/bin/testbench cms:doctor`.
- Services for tests run in Docker on cboxdk images: `ghcr.io/cboxdk/postgres:18` and `ghcr.io/cboxdk/valkey:8`; PHP on the dev image. Do not use Herd's Postgres for tests. Postgres 17 stays the minimum, so never use features that arrived in 18 (GUARDRAILS 1.2).
- `composer services:up` and `composer services:down` always run docker compose with the main checkout's `compose.yaml`, so no container of it ever mounts a worktree; from a worktree, services:up starts only Postgres and Valkey without recreating them, and services:down refuses. `docker compose exec php ...` therefore runs the main checkout's code; `composer check`, `composer test:affected` and `composer image:run` run the current checkout's in a container of their own.
- The single gates by hand: `composer lint:check`, `npm run format:check`, `composer rector:check`, `composer analyse`, `npm run typecheck`, `npm run lint`, `vendor/bin/pest --testsuite=<suite>` and `composer check:generated`, each runnable in the image with `composer image:run -- <command>`.

## Hvor ting bor

The namespace layout for the layers of GUARDRAILS 2.5. The Arch suite (`vendor/bin/pest --testsuite=Arch`, files in `tests/Arch`) enforces it over `packages/*/src` and `workbench/app`. The Arch suite checks the "May not use" column and the only-use rules for Domain, Actions and Infrastructure; the `mixed` and `array` column is enforced by the testkit's PHPStan rules in `packages/testkit/src/Phpstan`. This section is the same in `CLAUDE.md` and `AGENTS.md`; a test keeps them equal.

### Modules

Cbox CMS is one Composer package, `cboxdk/cms`, a library like `statamic/cms` (GUARDRAILS 2.6, PRD 2.32), with one `composer.json` at the repository root. There is no `composer.json` per module and no path repository for a module; the one path repository brings the workbench's fixture addon. The kernel is nine modules, each a namespace of the package:

| Module | Namespace | Directory | What it holds |
|---|---|---|---|
| contracts | `Cbox\Cms\Contracts` | `packages/contracts` | contracts, attributes, ids, the types the contracts take, and the JSON Schemas in `resources/schemas`; depends only on PHP |
| core | `Cbox\Cms\Core` | `packages/core` | the default implementations, the partition manager, the registry, the doctor, `config/cbox-cms.php` and the migrations |
| http | `Cbox\Cms\Http` | `packages/http` | the HTTP surface |
| cli | `Cbox\Cms\Cli` | `packages/cli` | the Artisan surface: `cms:build`, `cms:doctor`, `cms:partitions:maintain` |
| mcp | `Cbox\Cms\Mcp` | `packages/mcp` | the MCP surface: a tool per action exposed on MCP, for agents, on `laravel/mcp` behind its adapter |
| panel | `Cbox\Cms\Panel` | `packages/panel` | the PHP side of the control panel (PRD 13.4): `PanelRoutes`, the root view `cms-panel::app`, the Content-Security-Policy of its pages and the files of its build in `dist/`, which `composer panel:build` writes from `js/panel` and git ignores; a surface |
| identity | `Cbox\Cms\Identity` | `packages/identity` | login: the local accounts and their credential store in the schema `cms_identity`, on a connection and a role of its own, with `config/identity.php` and its migrations |
| generators | `Cbox\Cms\Generators` | `packages/generators` | the blueprint reader, the generators, `cms:generate` and `cms:schema:editor`; development only |
| testkit | `Cbox\Cms\Testkit` | `packages/testkit` | fakes, shared contract suites, the Postgres and Valkey harnesses, the PHPStan rules and the shared tool configuration in `config/`; development only |

- Layout. A module's code is `packages/<module>/src`, the PSR-4 root of its namespace in `autoload`; its tests are `packages/<module>/tests`, the PSR-4 root of `Cbox\Cms\<Module>\Tests` in `autoload-dev`; its other files sit beside them (`config/`, `database/migrations/`, `resources/schemas/`, `bin/`). Its service provider sits at the root of its namespace and is listed in `extra.laravel.providers`, and `testbench.yaml` registers the same providers before `WorkbenchServiceProvider`.
- The boundaries between the modules (`tests/Arch/ModulesTest.php`, `ModuleDependencies`), the dependency rules of `require` and `suggest`, why the layout stays, and what adding a module takes are on `docs/developers/architecture/modules.md`. A first-party module is a new namespace in the same package, never a package of its own; an addon is a package of its own that uses only `#[Stable]` and `#[Experimental]` API (GUARDRAILS 2.3).

### Layers

Code sits in a feature namespace below its module, and the layer is a namespace segment below the feature: in `Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore` the module is core, the feature `ReceiptStore` and the layer `Adapter`, and `Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant` has the same shape. "Module" in this file always means one of the modules above; the namespace below it is a feature. A namespace is in a layer when one of its segments is the layer name, either as the last segment or with more segments below it. When several segments match, the innermost one decides: `Cbox\Cms\Http\Boundary\RequestParser` is Boundary. `Domain` does not match `DomainEvents`.

| Segment | What lives there | May use | May not use |
|---|---|---|---|
| `Domain` | value objects, enums, invariants, domain interfaces | the domain and the contracts | everything else, including `Illuminate\Http`, facades and Eloquent |
| `Domain\Commands`, `Domain\Queries`, `Domain\Dto`, `Domain\Receipts` | command and query DTOs, other DTOs, receipts and results | as Domain | as Domain; only `final readonly` classes, no enums or interfaces |
| `Actions` | write actions, query actions and planners | the domain, the contracts, other classes in Actions | the framework, the DB facade, connections; only `final readonly` classes |
| `Boundary` | HTTP parsers, config readers, JSON decoders, queue payloads | the domain, the contracts, the framework, `mixed` and `array` | actions, surfaces |
| `Adapter` | implementations of contracts that need the framework or Postgres: the receipt and idempotency stores and their row mappers, Eloquent casts, Inertia props | the domain, the contracts, the framework, `mixed` and `array` | actions, surfaces |
| `Infrastructure` | Eloquent models, migration support, the partition manager | the domain, the contracts, `Illuminate\Database`, Boundary (the row mappers and error readers for what Postgres returns), casts in Adapter | `Illuminate\Http`, facades, actions, surfaces, `mixed` |
| `Jobs` | queue jobs; a surface | actions, DTOs, Boundary | Infrastructure, Adapter, Eloquent, the DB facade, connections |
| `Http` | the http module, `Cbox\Cms\Http`; a surface | actions, DTOs, Boundary | Infrastructure, Adapter, Eloquent |
| `Cli` | the cli module, `Cbox\Cms\Cli`; a surface. Artisan commands live in `Cli\Console`, never in a `Commands` namespace | actions, DTOs, Boundary | Infrastructure, Adapter, Eloquent |
| `Panel` | the panel module, `Cbox\Cms\Panel`; a surface. Its controllers hold no logic: they hand the request to a Boundary and an action, and `tests/Arch/PanelControllersTest.php` keeps them final readonly with only `__construct` and `__invoke` | actions, DTOs, Boundary, the framework's request and response, Inertia | Infrastructure, Adapter, Eloquent |
| `Mcp` | the mcp module, `Cbox\Cms\Mcp`; a surface. `laravel/mcp` is used only in `Mcp\Adapter`, whose server reaches the surface through the port `Mcp\Domain\McpEndpoint` | actions, DTOs, Boundary | Infrastructure, Adapter, Eloquent |

More rules, each in full with its check on `docs/developers/architecture/conventions.md`:

- The contracts module, `Cbox\Cms\Contracts`, is part of the domain and has no layer segments; every type in it is a final readonly class, an interface, an enum or a final exception. The attributes (`#[Stable]`, `#[Experimental]`, `#[Internal]`, `#[Command]`, `#[Query]`, `#[Hook]`, `#[Action]`, `#[Subscription]`) live in `Cbox\Cms\Contracts\Attributes`.
- `Commands`, `Queries`, `Dto` and `Receipts` namespaces sit only below `Domain` or in the contracts module.
- Every class, interface, trait and enum in `packages/*/src` carries exactly one of `#[Stable]`, `#[Experimental]` and `#[Internal]`; service providers are `#[Internal]`. Code outside `Cbox\Cms` may not use `#[Internal]` API (`cboxCms.internalUse`), so a Pest file here that uses internals declares a namespace below `Cbox\Cms`.
- Outbound HTTP, and everything that lets PHP fetch a URL or run a program (file, stream, socket, process, mail and XML functions, the framework's storage, mail, notification and image services, HTTP clients and the container ids that resolve them), is allowed only in the gateway namespace `Cbox\Cms\Core\Egress`, which goes through the SSRF guard. The names are the testkit's `EgressNames`, the allowed local uses are in `tests/Support/Arch/Egress.php`; see `docs/developers/architecture/egress.md`.
- The kernel knows no content types: `tests/Arch/ContentTypesTest.php` fails on any handle of the workbench's blueprints (all prefixed `fixture_`) in the kernel modules' `src`.
- `mixed`, untyped arrays and array shapes only in Boundary and Adapter (`cboxCms.mixed`, `cboxCms.untypedArray`, `cboxCms.arrayShape`); structured data is a DTO. Test code (a `Tests` namespace segment, or the global namespace of Pest files) is exempt, so `packages/*/src` and `workbench/app` never declare a `Tests` segment.
- `@phpstan-ignore` in any form only in Boundary and Adapter (`cboxCms.phpstanIgnore`); the testkit's own rules report errors no ignore can hide, except `cboxCms.internalUse` in an addon.
- Actions and Jobs never manage transactions (`cboxCms.transaction`, `cboxCms.savepoint`).
- Ids are value objects (PRD 5.3): outside Boundary and Adapter no public `$id` or `...Id` parameter and no `id()` or `...Id()` return is a string (`cboxCms.stringId`).
- Contracts live in `Cbox\Cms\Contracts` (GUARDRAILS 2.3), bound as singletons to the classes in `cbox-cms.contracts`; every configuration key sits under `cbox-cms.*`, the commands keep their `cms:*` names, and every contract has a fake and a shared suite in the testkit, run per implementation in `packages/<module>/tests/Contract`.
- Code asks the `Clock` contract for the time (`cboxCms.systemClock`; `hrtime(true)` for durations is allowed) and the `IdGenerator` contract for a new id (`cboxCms.uuid`); tests use `FakeClock` and `FakeIdGenerator`.
- Raw SQL (GUARDRAILS 6) only in migrations, Infrastructure and Adapter (`cboxCms.rawSql`); everything else uses the query builder.
- A hook class uses no egress and no database (`cboxCms.hookIo`), and nothing outside the core module and the testkit's fixture writers writes a kernel table by name (`cboxCms.kernelTableWrite`).
- Facades are imported by their full class name; no global aliases such as `\DB`, no real-time facades. A module's service provider sits at the root of its namespace and has no layer; a namespace without a layer segment has only the global rules, so put code in a layer. `declare(strict_types=1)` in every PHP file, and no `dd`, `dump`, `ddd`, `ray` or `var_dump`.
- The marker gate of GUARDRAILS 11 (`tests/Arch/MarkersTest.php`): no code or configuration file git lists carries `TODO`, `FIXME`, `XXX`, `placeholder` or `provisional` as a whole word; the one exception is the input hint attribute of a form control in `.tsx` and `.html`.
- Eloquent is strict for every model (GUARDRAILS 4.1), set once by `CoreServiceProvider::boot()`.

### Architecture notes

One page per area below `docs/developers/architecture/` (index `_index.md`): where the code lives, what it does, which tests hold it. Read the page before touching its area; a task that changes an area updates its page in the same commit, never this file.

- `modules.md`: module boundaries, dependencies, adding a module.
- `conventions.md`: the static rules above in full.
- `pipeline.md`: the pipeline shapes, the command pipeline, the commit, the wait after commit, the query pipeline, ports and fakes.
- `commands.md`: the entry, placement, release, publish, actor, grant, role and site commands; the access queries.
- `hooks-and-addons.md`: hooks, the addon manifest, panel contributions, the fixture addon.
- `egress.md`: what counts as egress; the egress and mail gateways.
- `identity.md`: identity contracts, the identity module, login policy, local accounts, sessions, login contracts, signals, breached passwords.
- `receipts-and-idempotency.md`: the receipt and idempotency stores, the commit position.
- `events.md`: the event log and `cms:events:run`.
- `caching.md`: the fragment store, the CDN driver, the invalidation subscriber.
- `database.md`: migrations, every kernel table, the partition manager.
- `access.md`: access tables, the actor context, the compiler, the policies.
- `long-running-work.md`: operations, `cms:types:rebuild`, `cms:seed-scale`.
- `registry.md`: `cms:build`, the registry files, the panel registry.
- `generators.md`: `cms:generate`, records, query builders, record codecs, validators, `cms:schema:editor`.
- `json-contracts.md`: the JSON Schemas, their codecs and TypeScript, the commands' and queries' schemas.
- `surfaces.md`: REST, Inertia, MCP, the CLI, the surface contract tests, the inspecting commands.
- `routing-and-delivery.md`: `path.resolve` and `GET /v1/resolve`.
- `doctor-and-errors.md`: `cms:doctor`, the error catalog, telemetry.
- `panel.md`: the panel module, its login, generated props, contributions, the SDK, the host runtime, the shell, themes and branding.
- `gates.md`: the dev image, `test:affected`, CI and mutation testing, the selftest, the JS gates, the Pest suites, the gate runner, the test databases, the tool caches, the services, the progress records.
- `docs-gate.md`: `composer docs:check` and the sections of `docs/`.

## When the PRD is unclear

- If the PRD is ambiguous but one reading is clearly consistent with the rest of the PRD and GUARDRAILS, pick it, implement it, and record the interpretation under "Tolkninger" in `PROGRESS.md`.
- If the PRD needs a small correction to be buildable, edit `PRD.md` in the planning repo, add a version entry at the top of its section 0, and commit it there with message `PRD v2.x: ...`.
- If it is a decision reserved for Sylvester (the open questions in PRD section 26, product scope, naming, licensing, anything outward-facing), do not decide. Add it under "Blokeret" in `PROGRESS.md` with what it blocks, and continue with work that does not depend on it.

## Autopilot

`.harness/autopilot.on` switches on the stop guard in `.harness/stop-hook.sh`. While it is on, a session may not stop until `PROGRESS.md` says `STATUS: complete` or `STATUS: blocked`. `.harness/waiting` marks that a background workflow is running and lets the session idle until it reports back. The workflow for one block is `.claude/workflows/cms-milestone.js`.

Delete `.harness/autopilot.on` to stop autopilot.
