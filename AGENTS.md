# AGENTS.md

Read `CLAUDE.md` in this repo. It applies to every agent, whatever the tool. The architecture is in `/Users/sylvester/Projects/cbox-cms/PRD.md`, the coding rules in `/Users/sylvester/Projects/cbox-cms/GUARDRAILS.md`, the build order in `/Users/sylvester/Projects/cbox-cms/MILESTONES.md`, and the working state in `PROGRESS.md`.

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
- Facades are imported by their full class name. Global aliases such as `\DB` and real-time facades are not allowed.
- A service provider at the package root has no layer. It wires contracts to adapters. A namespace without a layer segment has only the global rules; put code in a layer.
- `declare(strict_types=1)` in every PHP file, and no `dd`, `dump`, `ddd`, `ray` or `var_dump`.
