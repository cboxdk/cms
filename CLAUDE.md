# laravel-cms

Monorepo for Cbox CMS: the kernel, packages, sidecars and JS packages. Nothing is pushed anywhere; there is no remote yet.

## Sources of truth

These live in the planning repo and are read-only unless a rule below says otherwise:

- `/Users/sylvester/Projects/cbox-cms/PRD.md`: what is built (Danish).
- `/Users/sylvester/Projects/cbox-cms/GUARDRAILS.md`: how code is written. Every rule applies here.
- `/Users/sylvester/Projects/cbox-cms/MILESTONES.md`: the build order and exit criteria.
- `PROGRESS.md` in this repo: the working state. Read it first in every session and every agent task that changes code.

## Hard rules

- Never `git push`, never add a remote, never publish packages. Commit locally only.
- One commit per task, message `<block>-<task>: <what>`, for example `M1-T3: command envelope and idempotency store`.
- Follow GUARDRAILS 7.3: never weaken a check that verifies your own change (analysis config, test filters, snapshots, architecture tests, CI). If you believe a check is wrong, leave it, and add the case under "Til review af Sylvester" in `PROGRESS.md`.
- A bug fix has a regression test that fails before the fix.
- PHP 8.5, Laravel 13 only, PHPStan level 10 without baseline, Pest 4, Rector, Pint, React 19 with strict TypeScript.
- Run the checks before saying a task is done. Until `composer check` exists (milestone 0 creates it), run the individual tools that exist.
- The JS gates run from the root after `npm ci`: `npm run typecheck`, `npm run lint` and `npm run format:check`. The shared tsconfig, ESLint and Prettier configuration lives in the `js/tooling` workspace; the root files only point at it.
- The Pest suites are gate 5 of GUARDRAILS 10: `Unit`, `Codecs`, `Contract`, `Postgres` and `Arch` in `phpunit.xml`. A package puts tests for a suite in `packages/<package>/tests/<Suite>`; its other tests are `Unit`. The `Postgres` suite needs `composer services:up` and runs through the testkit's `RealPostgres` harness: the app role on the default connection, migrations by the owner role, no wrapping transaction, the owner truncates after each test, and a nested transaction fails the test. Helpers: `app(IndependentConnections::class)` and `app(ChildProcesses::class)`. The testkit's `RealValkey` harness runs there too: every Redis connection uses database index 15 and a key prefix per PHP process, `cms_test_<run id>_`, and the keys under it are removed with SCAN and UNLINK after each test and when the process ends. The run is `app(ValkeyRun::class)`.
- Services for tests run in Docker on cboxdk images: `ghcr.io/cboxdk/postgres:18` and `ghcr.io/cboxdk/valkey:8`; PHP on `ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1`. Do not use Herd's Postgres for tests. Postgres 17 stays the minimum, so never use features that arrived in 18 (GUARDRAILS 1.2).

## Hvor ting bor

The namespace layout for the layers of GUARDRAILS 2.5. The Arch suite (`vendor/bin/pest --testsuite=Arch`, files in `tests/Arch`) enforces it over `packages/*/src` and `workbench/app`. The Arch suite checks the "May not use" column and the only-use rules for Domain and Actions; the `mixed` and `array` column is enforced by the testkit's PHPStan rules in `packages/testkit/src/Phpstan`. This section is the same in `CLAUDE.md` and `AGENTS.md`; a test keeps them equal.

Code sits in a module below the package, and the layer is a namespace segment below the module: `Cbox\Cms\Core\Entries\Domain\Commands\ReleaseVariant`, `Cbox\Cms\Core\Receipts\Adapter\PostgresReceiptStore`. A namespace is in a layer when one of its segments is the layer name, either as the last segment or with more segments below it. When several segments match, the innermost one decides: `Cbox\Cms\Http\Boundary\RequestParser` is Boundary. `Domain` does not match `DomainEvents`.

| Segment | What lives there | May use | May not use |
|---|---|---|---|
| `Domain` | value objects, enums, invariants, domain interfaces | the domain and the contracts | everything else, including `Illuminate\Http`, facades and Eloquent |
| `Domain\Commands`, `Domain\Queries`, `Domain\Dto`, `Domain\Receipts` | command and query DTOs, other DTOs, receipts and results | as Domain | as Domain; only `final readonly` classes, no enums or interfaces |
| `Actions` | write actions, query actions and planners | the domain, the contracts, other classes in Actions | the framework, the DB facade, connections; only `final readonly` classes |
| `Boundary` | HTTP parsers, config readers, JSON decoders, queue payloads | the domain, the contracts, the framework, `mixed` and `array` | actions, surfaces |
| `Adapter` | implementations of contracts that need the framework or Postgres: the receipt and idempotency stores and their row mappers, Eloquent casts, Inertia props | the domain, the contracts, the framework, `mixed` and `array` | actions, surfaces |
| `Infrastructure` | Eloquent models, migration support, the partition manager | the domain, the contracts, `Illuminate\Database`, casts in Adapter | `Illuminate\Http`, facades, actions, surfaces, `mixed` |
| `Jobs` | queue jobs; a surface | actions, DTOs, Boundary | Infrastructure, Adapter, Eloquent, the DB facade, connections |
| `Http` | the http package, `Cbox\Cms\Http`; a surface | actions, DTOs, Boundary | Infrastructure, Adapter, Eloquent |
| `Cli` | the cli package, `Cbox\Cms\Cli`; a surface. Artisan commands live in `Cli\Console`, never in a `Commands` namespace | actions, DTOs, Boundary | Infrastructure, Adapter, Eloquent |

More rules:

- The contracts package, `Cbox\Cms\Contracts`, is part of the domain and has no layer segments. The attributes `#[Stable]`, `#[Experimental]`, `#[Internal]`, `#[Command]`, `#[Action]` and `#[Hook]`, and the enums `Surface` and `Phase`, live in `Cbox\Cms\Contracts\Attributes`.
- `Commands`, `Queries`, `Dto` and `Receipts` namespaces sit only below `Domain` or in the contracts package.
- Every class, interface, trait and enum in `packages/*/src` carries exactly one of `#[Stable]`, `#[Experimental]` and `#[Internal]`. Service providers are `#[Internal]`.
- Outbound HTTP (Guzzle, the Http facade, HTTP clients, `curl_*`, sockets, `file_get_contents`) is allowed only in the gateway namespace `Cbox\Cms\Core\Egress`, which goes through the SSRF guard.
- `mixed`, untyped arrays and array shapes are allowed only in Boundary and Adapter. The PHPStan rules report them in parameters, returns, properties, template bounds and class PHPDoc tags as `cboxCms.mixed`, `cboxCms.untypedArray` and `cboxCms.arrayShape`. A typed array is `list<T>` or `array<K, V>` where neither K nor V is `mixed`; `Foo[]` and `array<Foo>` have no key type and count as untyped. Structured data is a DTO. Closures are checked on their native types only.
- Test code, meaning a namespace with a `Tests` segment or the global namespace of Pest files, is exempt from that rule. Code in `packages/*/src` and `workbench/app` therefore always declares a namespace without a `Tests` segment; the Arch suite checks it.
- `@phpstan-ignore` in any form is allowed only in Boundary and Adapter. The PHPStan rule `cboxCms.phpstanIgnore` reads the tokens of every analysed file, tests included, and a token scan in the Arch suite checks `packages/*/src` and `workbench/app`. The testkit's own PHPStan rules report errors that no ignore comment and no `ignoreErrors` entry can hide. `cboxCms.internalUse` comes from PHPStan's restricted usage rules and is an ordinary error.
- `#[Internal]` sits on a class, a method or a class constant. Code outside the `Cbox\Cms` namespace, the global namespace included, may not use it (GUARDRAILS 2.3). The testkit's PHPStan extensions report every use, in code and in PHPDoc, as `cboxCms.internalUse`. A Pest file in this repo that uses internals therefore declares a namespace below `Cbox\Cms`, such as `Cbox\Cms\Tests\Feature` or `Cbox\Cms\Core\Tests`.
- Actions and Jobs never manage transactions (GUARDRAILS 4.1, PRD 4.2): no `transaction`, `beginTransaction`, `commit`, `rollBack`, `savepoint` or `createSavepoint` on a connection, the database manager, PDO or the DB facade, and no string containing `SAVEPOINT`. The PHPStan rules report them as `cboxCms.transaction` and `cboxCms.savepoint`; test code is exempt.
- Ids are value objects (PRD 5.3). Outside Boundary and Adapter, a public method parameter named `$id` or ending in `Id`, and the return of a public method named `id()` or ending in `Id`, may not be a string, nullable or not. The PHPStan rule reports `cboxCms.stringId`; test code is exempt. A value object takes its string as `$value`, and a Boundary parses the string into it.
- Contracts live in `Cbox\Cms\Contracts` (GUARDRAILS 2.3). `CoreServiceProvider` binds each one as a singleton to the class in `cms.contracts` (defaults in `packages/core/config/cms.php`); an application overrides one entry at a time. Every contract has a fake in the testkit and a shared suite there, a trait such as `Cbox\Cms\Testkit\Clock\ClockContract`. A package runs it in `tests/Contract` with one PHPUnit class per implementation.
- Code asks the `Clock` contract for the time instead of reading the system clock; only a clock implementation reads it. `now()` is UTC with microseconds and is not monotonic. Tests use the testkit's `FakeClock` (`set`, `advance`, `freeze`).
- Code asks the `IdGenerator` contract for a new id instead of making a UUID itself. `next()` returns a `Cbox\Cms\Contracts\Ids\Uuid7`, and a typed id such as `ChangesetId` wraps it. The first 48 bits are the Clock's unix milliseconds; ids from one instance are strictly increasing, also when the clock repeats a millisecond or steps back. `Uuid7::lowestAt()` and `highestAt()` give the bounds of a millisecond for partition ranges. A bad string throws `InvalidUuid7`. Tests use the testkit's seeded `FakeIdGenerator`.
- The `ReceiptStore` contract (`Cbox\Cms\Contracts\ReceiptStore`) has `store(Receipt)`, `find(ChangesetId): ?Receipt` and `markProjection(ChangesetId, ProjectionStatus): bool`; the status names the projection. `store()` and `markProjection()` run on the caller's connection, inside the caller's transaction when one is open, and never begin, end or savepoint one. Only committed receipts are stored: a Rejected or DryRun receipt throws `UnstorableReceipt`, a second receipt for a changeset throws `DuplicateReceipt` (both in `Contracts\Consistency`). `markProjection()` is idempotent, an acknowledgement is final, and it returns false and changes nothing when no live receipt lists the projection. Expiry is logical: `find()` returns null once the Clock is later than `RetentionClass::expiresAt()`, the changeset id's time plus 7 days for Standard; Evidence has none. The testkit's `FakeReceiptStore` is the store and the harness; `session()` gives connections with `begin`, `commit` and `rollBack`. The shared suite `ReceiptStoreContract` takes a `ReceiptStoreHarness` that hands out `ReceiptStoreSession`s. Testkit code for the store lives in the module `ReceiptStore`, not `Receipts`, because of the category rule.
- The receipt and idempotency types live in the contracts package, because the store contracts take them and the contracts package depends only on PHP: `Receipt` and `ProjectionStatus` in `Cbox\Cms\Contracts\Receipts` (final readonly DTOs), the enums `Outcome`, `WaitLevel`, `RetentionClass` and `ProjectionState` with `ProjectionName` and `InvalidReceipt` in `Cbox\Cms\Contracts\Consistency`, `ChangesetId` in `Cbox\Cms\Contracts\Ids`, and `IdempotencyKey`, `IdempotencyScope`, `ContentHash` and `PrincipalKind` in `Cbox\Cms\Contracts\Idempotency`. A committed outcome requires a `ChangesetId`; the others forbid one. The temporary JSON codec is `Cbox\Cms\Core\Codecs\Boundary\ReceiptCodecV1` (`#[Internal]`), tested in the `Codecs` suite; M1's generated codecs replace it. A `Receipts` segment anywhere in a namespace makes it the Receipts category, so a core module for the stores cannot be called `Receipts`.
- Facades are imported by their full class name. Global aliases such as `\DB` and real-time facades are not allowed.
- A service provider at the package root has no layer. It wires contracts to adapters. A namespace without a layer segment has only the global rules; put code in a layer.
- `declare(strict_types=1)` in every PHP file, and no `dd`, `dump`, `ddd`, `ray` or `var_dump`.

## When the PRD is unclear

- If the PRD is ambiguous but one reading is clearly consistent with the rest of the PRD and GUARDRAILS, pick it, implement it, and record the interpretation under "Tolkninger" in `PROGRESS.md`.
- If the PRD needs a small correction to be buildable, edit `PRD.md` in the planning repo, add a version entry at the top of its section 0, and commit it there with message `PRD v2.x: ...`.
- If it is a decision reserved for Sylvester (the open questions in PRD section 26, product scope, naming, licensing, anything outward-facing), do not decide. Add it under "Blokeret" in `PROGRESS.md` with what it blocks, and continue with work that does not depend on it.

## Autopilot

`.harness/autopilot.on` switches on the stop guard in `.harness/stop-hook.sh`. While it is on, a session may not stop until `PROGRESS.md` says `STATUS: complete` or `STATUS: blocked`. `.harness/waiting` marks that a background workflow is running and lets the session idle until it reports back. The workflow for one block is `.claude/workflows/cms-milestone.js`.

Delete `.harness/autopilot.on` to stop autopilot.
