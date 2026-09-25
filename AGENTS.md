# AGENTS.md

Read `CLAUDE.md` in this repo. It applies to every agent, whatever the tool. The architecture is in `/Users/sylvester/Projects/cbox-cms/PRD.md`, the coding rules in `/Users/sylvester/Projects/cbox-cms/GUARDRAILS.md`, the build order in `/Users/sylvester/Projects/cbox-cms/MILESTONES.md`, and the working state in `PROGRESS.md`.

## Hvor ting bor

The namespace layout for the layers of GUARDRAILS 2.5. The Arch suite (`vendor/bin/pest --testsuite=Arch`, files in `tests/Arch`) enforces it over `packages/*/src` and `workbench/app`. The Arch suite checks the "May not use" column and the only-use rules for Domain and Actions; the `mixed` and `array` column is the PHPStan rule from M0-T4. This section is the same in `CLAUDE.md` and `AGENTS.md`; a test keeps them equal.

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
- `@phpstan-ignore` is allowed only in Boundary and Adapter. A token scan finds it wherever it is, also where PHPStan would not.
- Facades are imported by their full class name. Global aliases such as `\DB` and real-time facades are not allowed.
- A service provider at the package root has no layer. It wires contracts to adapters. A namespace without a layer segment has only the global rules; put code in a layer.
- `declare(strict_types=1)` in every PHP file, and no `dd`, `dump`, `ddd`, `ray` or `var_dump`.
