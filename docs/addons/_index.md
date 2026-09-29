---
title: Addons
weight: 30
description: The extension points of the kernel an application or addon builds on, each with a running example that the test suites run.
---

# Addons

An addon, or an application, extends the kernel only through its extension points: contracts it may replace or decorate, attributes it declares commands and hooks with, checks it adds to `cms:doctor`, and the blueprint schema its types and fields are written in. Every extension point is documented on one page, and every page has at least one running example: the code on the page is a file in `examples/` that a test suite runs, byte for byte. `composer docs:check` fails when that stops being true.

An extension point is `#[Stable]` or `#[Experimental]`. Every extension point documented here is `#[Experimental]` today: public API an addon may use, without a compatibility promise yet, so it can change in a minor release. `#[Internal]` API is for the kernel alone, and the testkit's PHPStan rules report every use of it from outside `Cbox\Cms`.

- [Contracts](contracts/_index.md): the clock, the id generator, the receipt store and the idempotency store, each with its fake and shared suite.
- [Build declarations](build-declarations.md): scan roots, `#[Command]` and `#[Hook]`, and the registries `cms:build` compiles.
- [Doctor checks](doctor-checks.md): add a check to `cms:doctor` and test it.
- [Blueprint schema v1](blueprint-v1.md): the format of blueprint files, extensions of another owner's type, and addon field types.
- [Testing against real Postgres and Valkey](real-services.md): the testkit's harnesses for tests that need the real services.
- [Static analysis for addons](static-analysis.md): the testkit's PHPStan configuration for an addon.
- [Events](events.md): an event class with its versioned payload, what an event may carry, and the event log that the kernel writes and reads.
- [Error codes](errors.md): the error catalog, where every error gets its stable code, HTTP status, exit code and MCP response.
- [Commands and write actions](commands.md): `Command`, the `Envelope` a surface builds, `WriteAction` with its aggregates and versions, `#[Action]` and its surfaces, and the `WriteResult` a write ends with.
- [Plans and mutations](plans.md): the `Plan` a write action returns, the typed mutations and the kernel-generic field values of a revision.
- [Queries and query actions](queries.md): `Query`, `QueryAction` and the typed `Result` of a read.
