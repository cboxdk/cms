---
title: Architecture and layers
weight: 21
description: The package cboxdk/cms, its modules, the layers a namespace belongs to, and the rules the architecture tests and PHPStan hold.
---

# Architecture and layers

## The package and its modules

Cbox CMS is one Composer package, `cboxdk/cms`, with one `composer.json` at the root of the repository. The kernel is six modules in `packages/`, each a namespace with its code in `packages/<module>/src` and its tests in `packages/<module>/tests`:

| Module | Namespace | What it holds |
|---|---|---|
| `contracts` | `Cbox\Cms\Contracts` | The contracts, the attributes, the ids and the types the contracts take. It depends only on PHP. |
| `core` | `Cbox\Cms\Core` | The default implementations, the partition manager, the registry, the doctor and the migrations. |
| `generators` | `Cbox\Cms\Generators` | The blueprint reader, the generators, and their commands `cms:generate` and `cms:schema:editor`. |
| `cli` | `Cbox\Cms\Cli` | The Artisan commands `cms:build`, `cms:doctor` and `cms:partitions:maintain`. |
| `http` | `Cbox\Cms\Http` | The HTTP surface. Today it holds only its service provider. |
| `testkit` | `Cbox\Cms\Testkit` | The fakes, the shared suites, the Postgres and Valkey harnesses and the PHPStan rules. |

No package boundary keeps the modules apart, so the Arch suite does (`tests/Arch/ModulesTest.php`): contracts depends only on PHP, core, http and cli never use the testkit or the generators, and no production module uses a package that `composer.json` only suggests. The testkit's and the generators' heavy dependencies, the analysis tools, Testbench, `symfony/yaml` and `opis/json-schema`, are in `suggest` and `require-dev`, never in `require`, so they never reach production; see [Requirements](../requirements.md).

The rest of the repository is tooling: `workbench/` is the application the commands run in, `tools/` holds the gate runner and the other scripts behind the Composer scripts, `examples/` holds the running examples of these pages, and `js/` the shared JavaScript configuration.

## Modules and layers

Code sits in a module below the package, and the layer is a namespace segment below the module, as in `Cbox\Cms\Core\ReceiptStore\Adapter\PostgresReceiptStore`: the module is `ReceiptStore` and the layer `Adapter`. A namespace is in a layer when one of its segments is the layer's name; when several match, the innermost decides.

| Layer | What lives there | May use |
|---|---|---|
| `Domain` | Value objects, enums, invariants and domain interfaces. `Domain\Commands`, `Domain\Queries`, `Domain\Dto` and `Domain\Receipts` hold only `final readonly` classes. | The domain and the contracts. |
| `Actions` | Write actions, query actions and planners, as `final readonly` classes. | The domain, the contracts and other actions; not the framework, the DB facade or a connection. |
| `Boundary` | Parsers and readers of what comes from outside: HTTP input, configuration, JSON, queue payloads. | The domain, the contracts and the framework, with `mixed` and untyped arrays. |
| `Adapter` | Implementations of contracts that need the framework or Postgres, such as the stores. | The domain, the contracts and the framework, with `mixed` and untyped arrays. |
| `Infrastructure` | Eloquent models, migration support and the partition manager. | The domain, the contracts, `Illuminate\Database` and Boundary. |
| `Jobs`, `Http`, `Cli` | The surfaces: queue jobs, the HTTP package and the CLI package. Artisan commands live in `Cli\Console`. | Actions, DTOs and Boundary; not Infrastructure, Adapter or Eloquent. |

A service provider sits at the root of its package, with no layer, and binds contracts to adapters.

## The rules that hold it

- **The Arch suite** (`vendor/bin/pest --testsuite=Arch`) checks the layers over `packages/*/src` and `workbench/app`: what each layer may use, that every class, interface, trait and enum carries exactly one of `#[Stable]`, `#[Experimental]` and `#[Internal]`, that outbound requests happen only in the egress gateway (see [Egress](../security/egress.md)), and that the kernel names no content type.
- **The testkit's PHPStan rules** report `mixed`, untyped arrays and array shapes outside Boundary and Adapter, raw SQL outside migrations, Infrastructure and Adapter, transactions in Actions and Jobs, a string used as an id, a read of the system clock outside a `Clock`, a UUID made outside an `IdGenerator`, and a use of `#[Internal]` API from outside `Cbox\Cms`. Most of them cannot be hidden by an ignore comment.
- **`declare(strict_types=1)`** is in every PHP file, and PHPStan runs at level 10 with no baseline.

Stability is part of the API. `#[Stable]` promises compatibility. `#[Experimental]` is public API an addon may use, which can still change in a minor release. `#[Internal]` is for the kernel alone.

## Contracts and their bindings

A contract is an interface in `Cbox\Cms\Contracts`. `CoreServiceProvider` binds each one as a singleton to the class named in `cbox-cms.contracts`, and an application overrides one entry at a time in its own `config/cbox-cms.php`. Every contract has a fake and a shared suite in the testkit. The contracts and how to replace them are on [Contracts](../addons/contracts/_index.md).

## Registries

A package declares its commands and hooks with attributes and names the directories to scan in its service provider. `cms:build` reads the declarations with reflection, once, and compiles them to PHP files in `bootstrap/cache/cms/`; at run time the kernel reads those files. See [Build declarations](../addons/build-declarations.md).
