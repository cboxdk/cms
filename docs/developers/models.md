---
title: Infrastructure models
weight: 27
description: The Eloquent models of the Infrastructure layer, and the strictness the kernel sets for every model: missing attributes, silent assignments and lazy loading.
---

# Infrastructure models

Eloquent models live in the `Infrastructure` layer of a module (see [Architecture and layers](architecture.md)). The domain, the actions and the surfaces (Jobs, Http and Cli) never use a model: an Infrastructure class maps rows to domain objects and DTOs, and the Arch suite holds the other layers to it.

## Strictness

`CoreServiceProvider` makes Eloquent strict for every model of the process when it boots (GUARDRAILS 4.1). It is set once there, never per model, and a model cannot opt out.

| Violation | Outside production | In production |
|---|---|---|
| Reading an attribute the query did not load, such as `note` after `select(['id', 'name'])` | Throws `MissingAttributeException`. | Throws `MissingAttributeException`. |
| Mass-assigning an attribute that is not fillable, with `new`, `fill()`, `create()` or `update()` | Throws `MassAssignmentException`, so `create()` stores nothing. | Throws `MassAssignmentException`, so `create()` stores nothing. |
| Loading a relation lazily on a model that a query returned with other models | Throws `LazyLoadingViolationException`. | Logs an error through the `LoggerInterface` with the model's class and the relation, then loads the relation. |

So a missing column or a dropped value never passes as `null` or as silence, in any environment. An N+1 fails every test that runs into it, and one that reaches production costs a log line instead of a failed request. Load the relations a caller needs with `with()`, or `load()` on a collection.

Production is the environment the application reports as `production`. The kernel sets the violation handlers on each boot, and outside production it clears the lazy-loading handler, so the behaviour follows the environment of the process that booted last.

## Testing a model

A test that uses a model runs in the `Postgres` suite against the testkit's `RealPostgres` harness, so the strictness above holds in the test as it does in the application. The kernel's own test, `packages/core/tests/Postgres/EloquentStrictnessTest.php`, uses test-only models in a `Tests` namespace on scratch tables that the owner role makes, and boots `CoreServiceProvider` again in the environment it names to show the production behaviour.
