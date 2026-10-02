---
title: Architecture and layers
weight: 21
description: The package cboxdk/cms, its modules and where they live, the boundaries between them, the layers a namespace belongs to, and the rules the architecture tests and PHPStan hold.
---

# Architecture and layers

## The package and its modules

Cbox CMS is one Composer package, `cboxdk/cms`, a library, with one `composer.json` at the root of the repository. The kernel is eight modules in `packages/`, each a namespace with its code in `packages/<module>/src` and its tests in `packages/<module>/tests`:

| Module | Namespace | What it holds |
|---|---|---|
| `contracts` | `Cbox\Cms\Contracts` | The contracts, the attributes, the ids and the types the contracts take. It depends only on PHP. |
| `core` | `Cbox\Cms\Core` | The default implementations, the partition manager, the registry, the doctor and the migrations. |
| `generators` | `Cbox\Cms\Generators` | The blueprint reader, the generators, and their commands `cms:generate` and `cms:schema:editor`. |
| `cli` | `Cbox\Cms\Cli` | The Artisan commands `cms:build`, `cms:doctor` and `cms:partitions:maintain`. |
| `http` | `Cbox\Cms\Http` | The HTTP surface. Today it holds only its service provider. |
| `mcp` | `Cbox\Cms\Mcp` | The MCP surface: one tool per action exposed on MCP, for agents, served by `laravel/mcp` behind the module's adapter. |
| `identity` | `Cbox\Cms\Identity` | Login: the local accounts and their credential store, in the schema `cms_identity` on a connection and a role of its own (see [Credential store](../security/credential-store.md)). The actor aggregate and the actor commands stay in the core, in `Cbox\Cms\Core\Identity`. |
| `testkit` | `Cbox\Cms\Testkit` | The fakes, the shared suites, the Postgres and Valkey harnesses and the PHPStan rules. |

A module's service provider sits at the root of its namespace, such as `Cbox\Cms\Core\CoreServiceProvider`, and is listed in `extra.laravel.providers`, so an application discovers it. Its other files sit beside `src` and `tests`: `config/`, `database/migrations/`, `resources/schemas/` and `bin/`.

### Why the code stays below packages/

The modules were separate Composer packages before they became one, and their directories stayed where they were. The directory names the module, and much reads it: the Arch suite scopes its rules by `packages/*/src`, `phpstan.neon` and `rector.php` list the modules' directories, the content type rule scans the src of each kernel module, the documentation gate finds the extension points in `packages/*/src` and `packages/*/resources/schemas`, and mutation testing picks the changed files below `packages/*/src`. Outside the repository, an addon's PHPStan configuration includes `vendor/cboxdk/cms/packages/testkit/config/phpstan.neon`, and an application's blueprint files point at `vendor/cboxdk/cms/packages/contracts/resources/schemas/blueprint.v1.json`. Moving the code to `src/<Module>` would change every one of these paths, and every file's history, without changing what the code does.

### The boundaries between the modules

No package boundary keeps the modules apart, so the Arch suite does, in `tests/Arch/ModulesTest.php`:

- The repository is the one library `cboxdk/cms`, with no `composer.json` per module, and every directory below `packages/` is a module, autoloaded from its `src` and its tests from its `tests`.
- Contracts uses no other module and no package, only PHP.
- Core, http, cli and mcp never use the testkit, the generators or identity, identity never uses the testkit or the generators, and the generators never use the testkit. The testkit uses no module but contracts.
- No module uses the repository's tests, tooling, workbench or examples.
- Every package a module uses is in `require` or `suggest`, and the modules an application runs in production, contracts, core, http, cli, mcp and identity, use none that is only suggested.

The last tests of the file plant each kind of violation in a scratch directory and check that the rule reports it. The layers below and the rule that the kernel names no content type hold in every module.

### Dependencies for development only

`require` holds only what the production modules use: PHP, the Composer runtime, the `illuminate/*` packages they use, `psr/clock`, `psr/log`, `symfony/console` and `symfony/process`. The testkit's and the generators' heavy dependencies, PHPStan, Larastan, Rector, `driftingly/rector-laravel`, Pint, Testbench, PHPUnit, `symfony/yaml` and `opis/json-schema`, are in `suggest`, each with its reason, and in this repository's `require-dev`. An application or addon that uses the testkit or `cms:generate` puts them in its own `require-dev`, so they never reach production. `cms:generate` checks for `symfony/yaml` and `opis/json-schema` before it reads a blueprint and names the `composer require --dev` command when one is missing. [Requirements](../requirements.md) lists both.

### Modules to come

A first-party module is a new namespace in the same package, never a package of its own, as MCP is `Cbox\Cms\Mcp` in `packages/mcp`. The panel's PHP side comes as `Cbox\Cms\Panel` in `packages/panel`, with its React code in `js/panel`, and a module that owns content types, such as end-user accounts with the member profile, as `Cbox\Cms\Members` in `packages/members`, with its types in its own schema files. A new module gets its autoload entries, its provider in `extra.laravel.providers` and `testbench.yaml`, and its place in the module rules of the Arch suite. E-commerce is an addon, a package of its own that requires `cboxdk/cms` and uses only its `#[Stable]` and `#[Experimental]` API.

The rest of the repository is tooling: `workbench/` is the application the commands run in, `tools/` holds the gate runner and the other scripts behind the Composer scripts, `examples/` holds the running examples of these pages, and `js/` the shared JavaScript configuration.

## Features and layers

Code sits in a feature namespace below its module, and the layer is a namespace segment below the feature, as in `Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore`: the module is core, the feature `ReceiptStore` and the layer `Adapter`. A namespace is in a layer when one of its segments is the layer's name; when several match, the innermost decides.

| Layer | What lives there | May use |
|---|---|---|
| `Domain` | Value objects, enums, invariants and domain interfaces. `Domain\Commands`, `Domain\Queries`, `Domain\Dto` and `Domain\Receipts` hold only `final readonly` classes. | The domain and the contracts. |
| `Actions` | Write actions, query actions and planners, as `final readonly` classes. | The domain, the contracts and other actions; not the framework, the DB facade or a connection. |
| `Boundary` | Parsers and readers of what comes from outside: HTTP input, configuration, JSON, queue payloads. | The domain, the contracts and the framework, with `mixed` and untyped arrays. |
| `Adapter` | Implementations of contracts that need the framework or Postgres, such as the stores. | The domain, the contracts and the framework, with `mixed` and untyped arrays. |
| `Infrastructure` | Eloquent models, migration support and the partition manager. | The domain, the contracts, `Illuminate\Database` and Boundary. |
| `Jobs`, `Http`, `Cli`, `Mcp` | The surfaces: queue jobs, the http module, the cli module and the mcp module. Artisan commands live in `Cli\Console`. `laravel/mcp` is used only in `Mcp\Adapter`, whose server reaches the surface through the port `Mcp\Domain\McpEndpoint`. | Actions, DTOs and Boundary; not Infrastructure, Adapter or Eloquent. |

A module's service provider sits at the root of its namespace, with no layer, and binds contracts to adapters.

## The rules that hold it

- **The Arch suite** (`vendor/bin/pest --testsuite=Arch`) checks the layers over `packages/*/src` and `workbench/app`: what each layer may use, that every class, interface, trait and enum carries exactly one of `#[Stable]`, `#[Experimental]` and `#[Internal]`, that outbound requests happen only in the egress gateway (see [Egress](../security/egress.md)), and that the kernel names no content type.
- **The testkit's PHPStan rules** report `mixed`, untyped arrays and array shapes outside Boundary and Adapter, raw SQL outside migrations, Infrastructure and Adapter, transactions in Actions and Jobs, a string used as an id, a read of the system clock outside a `Clock`, a UUID made outside an `IdGenerator`, and a use of `#[Internal]` API from outside `Cbox\Cms`. Most of them cannot be hidden by an ignore comment.
- **`declare(strict_types=1)`** is in every PHP file, and PHPStan runs at level 10 with no baseline.

Stability is part of the API. `#[Stable]` promises compatibility. `#[Experimental]` is public API an addon may use, which can still change in a minor release. `#[Internal]` is for the kernel alone.

## Contracts and their bindings

A contract is an interface in `Cbox\Cms\Contracts`. `CoreServiceProvider` binds each one as a singleton to the class named in `cbox-cms.contracts`, and an application overrides one entry at a time in its own `config/cbox-cms.php`. Every contract has a fake and a shared suite in the testkit. The contracts and how to replace them are on [Contracts](../addons/contracts/_index.md).

## Registries

A module or an addon declares its commands, queries, actions and hooks with attributes and names the directories to scan in its service provider. `cms:build` reads the declarations with reflection, once, and compiles them to PHP files in `bootstrap/cache/cms/`; at run time the kernel reads those files. See [Build declarations](../addons/build-declarations.md).
