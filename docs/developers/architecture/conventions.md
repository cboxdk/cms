---
title: Conventions and static rules
weight: 33
description: The rules every module follows and the Arch tests and PHPStan rules that hold them: attributes, mixed and arrays, ignores, internals, transactions, ids, contracts, the Clock, ids, raw SQL, hook IO, kernel table writes, the marker gate and strict Eloquent.
---

# Conventions and static rules

Each rule is stated in one line in `CLAUDE.md` under "Layers". This page holds the full rule, what the check reads and where its tests live.

## The contracts module

The contracts module, `Cbox\Cms\Contracts`, is part of the domain and has no layer segments. The attributes `#[Stable]`, `#[Experimental]`, `#[Internal]`, `#[Command]`, `#[Query]` and `#[Hook]`, and the enum `Phase`, live in `Cbox\Cms\Contracts\Attributes`. `#[Action(handles: ..., surfaces: [...])]`, the enum `Surface` (`Rest`, `Inertia`, `Mcp`, `Cli`) and `UnknownSurface`, which `#[Action]` throws for anything in `surfaces` that is not a case, live there too; `cms:build` registers the actions in `actions.php`. `#[Subscription(name, events: [...], lane: ..., projection: ...)]`, with `UnknownLane` and `UnknownEvent`, lives there too, and the subscriber contract `Subscriber` (`handle(StoredEvent)`), the enum `Lane` (`Critical`, `Standard`, `External`, `Revalidate`, `Background`, PRD 7.6) and `SubscriptionName` live in `Cbox\Cms\Contracts\Subscribers`; `cms:build` registers the subscribers in `subscribers.php` (`docs/addons/subscribers.md`). Every type in `Cbox\Cms\Contracts` is a final readonly class, an interface, an enum or a final exception (`tests/Arch/ContractsShapesTest.php`).

## Stability attributes

Every class, interface, trait and enum in `packages/*/src` carries exactly one of `#[Stable]`, `#[Experimental]` and `#[Internal]`. Service providers are `#[Internal]`.

## The kernel knows no content types

The kernel knows no content types (GUARDRAILS 2.4). `tests/Arch/ContentTypesTest.php` reads every blueprint below `workbench/schema` with symfony/yaml and takes the type handles, the field handles (groups included) and the select values. Each starts with `fixture_` (`ContentTypeScan::PREFIX`; the test fails on one that does not), so no handle is an ordinary word such as `title`, and the test fails on each match in the text of the files below `packages/{contracts,core,testkit,generators,http,cli,mcp,identity,panel}/src`, comments included, reported as `file:line: text`: a handle matches anywhere in a line, in any case, with each underscore written as `_`, `-`, several of them or nothing, so `fixture_article` also matches `FixtureArticle`, `$fixtureArticle` and `fixture-article-id`. The scanner is `tests/Support/Arch/ContentTypeScan.php`, covered by `tests/Feature/Tooling/ContentTypeScanTest.php`, and the selftest plants `'fixture_article'` in `Selftest\Domain\ContentType`. Test data in the kernel's modules uses neutral words, such as `{"value":"A"}` for a content hash.

## Mixed, untyped arrays and array shapes

`mixed`, untyped arrays and array shapes are allowed only in Boundary and Adapter. The PHPStan rules report them in parameters, returns, properties, class constants (the var tag, or else the native type, so a `const array` needs a typed var tag), template bounds and class PHPDoc tags as `cboxCms.mixed`, `cboxCms.untypedArray` and `cboxCms.arrayShape`. A typed array is `list<T>` or `array<K, V>` where neither K nor V is `mixed`; `Foo[]` and `array<Foo>` have no key type and count as untyped. Structured data is a DTO. Closures are checked on their native types only.

## Test code

Test code, meaning a namespace with a `Tests` segment or the global namespace of Pest files, is exempt from that rule. Code in `packages/*/src` and `workbench/app` therefore always declares a namespace without a `Tests` segment; the Arch suite checks it.

## PHPStan ignores

`@phpstan-ignore` in any form is allowed only in Boundary and Adapter. The PHPStan rule `cboxCms.phpstanIgnore` reads the tokens of every analysed file, tests included, and a token scan in the Arch suite checks `packages/*/src` and `workbench/app`. The testkit's own PHPStan rules report errors that no ignore comment and no `ignoreErrors` entry can hide. The one exception is `cboxCms.internalUse` in an addon: a comment that names it, `@phpstan-ignore cboxCms.internalUse (reason)`, hides one use on its line for each naming; `@phpstan-ignore-line`, `@phpstan-ignore-next-line`, a comment for other identifiers and an `ignoreErrors` entry never do. `docs/addons/static-analysis.md` documents it for addon authors.

## Internal API

`#[Internal]` sits on a class, a method or a class constant. Code outside the `Cbox\Cms` namespace, the global namespace included, may not use it (GUARDRAILS 2.3). The testkit's PHPStan extensions report every use, in code and in PHPDoc, as `cboxCms.internalUse`: PHPStan's restricted usage rules find the uses, `InternalUseIgnoreErrorExtension` takes each out of the file's ignorable errors and emits it as collected data, and `InternalUseRule` reports it after the analysis, non-ignorable unless a comment on its line names the identifier. `InternalUseCollector` reads those lines from the `linesToIgnore` attribute PHPStan's parser sets, so a waiver sits on the line PHPStan applies the comment to. A Pest file in this repo that uses internals therefore declares a namespace below `Cbox\Cms`, such as `Cbox\Cms\Tests\Feature` or `Cbox\Cms\Core\Tests`.

## Transactions

Actions and Jobs never manage transactions (GUARDRAILS 4.1, PRD 4.2): no `transaction`, `beginTransaction`, `commit`, `rollBack`, `savepoint` or `createSavepoint` on a connection, the database manager, PDO or the DB facade, and no string containing `SAVEPOINT`. The PHPStan rules report them as `cboxCms.transaction` and `cboxCms.savepoint`; test code is exempt.

## Ids are value objects

Ids are value objects (PRD 5.3). Outside Boundary and Adapter, a public method parameter named `$id` or ending in `Id`, and the return of a public method named `id()` or ending in `Id`, may not be a string, nullable or not. The PHPStan rule reports `cboxCms.stringId`; test code is exempt, and so is a signature the framework requires: a method that implements or overrides a method of a PHP, `Illuminate`, `Symfony` or `Psr` interface or parent class whose declaration, PHPDoc included, has a string in that place (a constructor only when that declaration is abstract). The method's name never exempts it, so a job's `uniqueId(): string` is reported, because `ShouldBeUnique` declares no method. A value object takes its string as `$value`, and a Boundary parses the string into it.

## Contracts and their bindings

Contracts live in `Cbox\Cms\Contracts` (GUARDRAILS 2.3). `CoreServiceProvider` binds each one as a singleton to the class in `cbox-cms.contracts` (defaults in `packages/core/config/cbox-cms.php`); an application overrides one entry at a time. The configuration is `config/cbox-cms.php`, every key sits under `cbox-cms.*` (`cbox-cms.contracts`, `cbox-cms.database`, `cbox-cms.doctor`, `cbox-cms.generators`), and the commands keep their `cms:*` names (GUARDRAILS 2.3). Every contract has a fake in the testkit and a shared suite there, a trait such as `Cbox\Cms\Testkit\Clock\ClockContract`. A module runs it in `packages/<module>/tests/Contract`, an addon in its own `tests/Contract`, with one PHPUnit class per implementation.

## The Clock

Code asks the `Clock` contract for the time instead of reading the system clock; only a clock implementation, a class that implements `Clock`, reads it. The testkit's PHPStan rule `SystemClockRule` reports every other read as `cboxCms.systemClock`, non-ignorable and in every layer; test code is exempt, meaning a namespace with a `Tests` segment or a file in the global namespace below a `tests` directory (`LayerScope::isTestFile()`), so migrations, config files and route files are checked. A read is `time()`, `microtime()`, `uniqid()`, `now()`, `date()` without a timestamp, `strtotime()` without a base, `new DateTimeImmutable()` or `date_create()` without a date or with a constant relative one such as `'now'` or `'+1 day'`, `createFromFormat()` with a constant format that leaves a field to the clock, Carbon's `now()`, `parse()` and comparisons with now, or `$_SERVER['REQUEST_TIME']` (the full list is `ClockReads`). `hrtime(true)` measures durations and deadlines of real waits and is allowed. `now()` is UTC with microseconds and is not monotonic. Tests use the testkit's `FakeClock` (`set`, `advance`, `freeze`). The core's `SystemClock` and the testkit's `FakeClock` also implement PSR-20's `Psr\Clock\ClockInterface` (`psr/clock`, required by core and testkit), so a library that asks for a PSR-20 clock gets the same clock; the `Clock` contract does not extend it, because the contracts module depends only on PHP.

## The IdGenerator

Code asks the `IdGenerator` contract for a new id instead of making a UUID itself; only a class that implements `IdGenerator` makes one. The testkit's PHPStan rule `UuidCreationRule` reports every other one as `cboxCms.uuid`, non-ignorable; test code is exempt, read as for `cboxCms.systemClock`. It covers `Str::uuid()`, `uuid7()`, `orderedUuid()` and `ulid()`, the Eloquent traits `HasUuids`, `HasVersion4Uuids` and `HasUlids`, the random and time-based factories of ramsey/uuid and symfony/uid, and `uuid_create()` (the full list is `UuidCreations`). `next()` returns a `Cbox\Cms\Contracts\Ids\Uuid7`, and a typed id such as `ChangesetId` wraps it. The first 48 bits are the Clock's unix milliseconds; ids from one instance are strictly increasing, also when the clock repeats a millisecond or steps back. `Uuid7::lowestAt()` and `highestAt()` give the bounds of a millisecond for partition ranges. A bad string throws `InvalidUuid7`. Tests use the testkit's seeded `FakeIdGenerator`.

## Raw SQL

Raw SQL (GUARDRAILS 6) is allowed only in migrations and in the Infrastructure and Adapter layers; everything else uses the query builder. The testkit's PHPStan rule reports `cboxCms.rawSql`, non-ignorable, for the SQL-taking methods of a connection, the database manager and the DB facade (`select`, `statement`, `raw` and the like), the `*Raw` methods of the query and Eloquent builders, relations and models, `query`, `exec` and `prepare` on PDO, and `new Expression`. Test code and the global namespace, where migrations live, are not checked. The testkit's own raw SQL lives in `Cbox\Cms\Testkit\Postgres\Infrastructure`.

## Hook IO and kernel table writes

Two testkit PHPStan rules keep writes and IO where PRD 6.3, 6.5 (invariants 1 and 13) and 11.12 put them; both check test code too and report non-ignorable errors. `HookIoRule` (`cboxCms.hookIo`): a class that implements `AuthorizeHook`, `TransformHook` or `ValidateHook` (`Phase::hookInterface()`), with the traits it uses, may not use a name of `EgressNames` or of its own `DATABASE_CLASSES`, `DATABASE_FUNCTIONS`, `DATABASE_FUNCTION_PREFIXES` and `DATABASE_SERVICE_IDS` (the DB facade, `Illuminate\Database`, Eloquent models through their parent, the cache, Redis, PDO, `pg_*`): in a call, `new`, a static call, a constant, `instanceof`, a method call on it, a parameter, return or property type, a container id given to `app()`, `resolve()` or a container, or a class name or, where the call takes a callable, a function name given as a string. First-class callables reach it through the three `*CallablesRule` classes. It checks the hook class itself, not the classes it calls. `KernelTableWriteRule` (`cboxCms.kernelTableWrite`): outside the core module (`Cbox\Cms\Core` and files below `packages/core`, its migrations) and the testkit's fixture writers (`KernelTableWriteRule::FIXTURE_WRITERS`, `Cbox\Cms\Testkit\FixtureWriters`, where `PostgresIdentitySeeder` lives), no code writes a kernel table, `KernelTables::NAMES` or a managed partition `<table>_p<digits>`, by name: a query builder write (`insert`, `update`, `upsert`, `delete`, `truncate` and the rest of `WRITE_METHODS`) whose chain starts at `table()` or `from()` with a constant kernel table on a connection, the database manager, the DB facade or a builder, or constant SQL that inserts into, updates, deletes from, truncates, merges into or copies into one, given to a method that takes SQL or to `new Expression`. A name in a variable the chain does not show is not seen. `tests/Postgres/KernelTablesTest.php` holds `KernelTables::NAMES` equal to the tables and LIST partitions the migrations build (less `migrations` and `operations`), and `tests/Arch/FixtureWritersTest.php` (`Tests\Support\Arch\FixtureWriters`) fails when a file outside `packages/testkit/src` names the fixture writers. PRD 11.12's third rule, that a hook reads no field above its classification access, holds at run time: `HookPlans` gives every hook a `PlanView` filtered to the call's classification access. The selftest plants `CachedHook` in the core and `KernelWrite` in `workbench/app/Selftest` (`Plants::WORKBENCH`).

## Facades

Facades are imported by their full class name. Global aliases such as `\DB` and real-time facades are not allowed.

## Service providers

A module's service provider, at the root of its namespace such as `Cbox\Cms\Core\CoreServiceProvider`, has no layer. It wires contracts to adapters. A namespace without a layer segment has only the global rules; put code in a layer.

## Strict types and debug output

`declare(strict_types=1)` in every PHP file, and no `dd`, `dump`, `ddd`, `ray` or `var_dump`.

## The marker gate

The marker gate of GUARDRAILS 11 is the Arch test `tests/Arch/MarkersTest.php`, with `Cbox\Cms\Tests\Support\Arch\MarkerScan`: no code or configuration file carries `TODO`, `FIXME`, `XXX`, `placeholder` (also `placeholders`) or `provisional`, matched as whole words in any case, as `git grep -w -i` matches them. It reads every text file that `git ls-files` lists, tracked files and untracked files that are not ignored, whatever its name or extension: code, `bin/ci`, the ini and conf files under `docker/`, `workbench/.env.example`, dotfiles such as `.prettierrc` and `.gitignore`, and the PHPStan fixtures `*.php.inc`. A file is binary, and not read, when its first 8000 bytes (`MarkerScan::BINARY_PROBE_BYTES`) hold a NUL byte, as git decides it; symlinks and submodules are left out. `MarkerScan::EXCLUDED` leaves out Markdown, `composer.lock`, `package-lock.json`, `.claude/` and `.harness/`; `tests/Feature/Tooling/MarkerGateTest.php` holds the list. Each hit is reported as `<file>:<line>: <words>`. The gate scans the checkout with `MarkerScan::ofCheckout()`, which holds `Tests\Support\JsToolchainLock` shared, so the probe files the JS toolchain tests write and delete under the exclusive lock never appear in it, also in a parallel run. Code that must name a word writes it in parts, as the gate's own files do. The one exception, in `.tsx` and `.html` files only, is the input hint of a form control, found by `Cbox\Cms\Tests\Support\Arch\InputHintAttributes`: the attribute written `placeholder="`, `placeholder='` or, in `.tsx`, `placeholder={`, preceded by whitespace, in lower case and outside comments, strings, template literals, HTML text and quoted attribute values. The word anywhere else in those files, such as a comment, a variable, an attribute value, JSX text or a key in an object literal, a props type or an interface, and the attribute in every other file type still fail.

## Strict Eloquent

Eloquent is strict for every model (GUARDRAILS 4.1): `CoreServiceProvider::boot()` sets it once, never per model. Reading an attribute the query did not load (`MissingAttributeException`) and mass-assigning one that is not fillable (`MassAssignmentException`) throw in every environment, production included; a lazy load throws `LazyLoadingViolationException` outside production, and in production is logged as an error through `LoggerInterface` with the model and relation and then loads. The violation handlers are set on each boot, the lazy-loading one cleared outside production. `packages/core/tests/Postgres/EloquentStrictnessTest.php` covers it with test-only models on scratch tables, booting the provider again in the environment it names; `docs/developers/models.md` documents it.
