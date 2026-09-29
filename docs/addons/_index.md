---
title: Addons
weight: 30
description: The extension points of the kernel an application or addon builds on, each with a running example that the test suites run.
---

# Addons

An addon, or an application, extends the kernel only through its extension points: contracts it may replace or decorate, attributes it declares commands, queries, actions, hooks and subscribers with, checks it adds to `cms:doctor`, and the blueprint schema its types and fields are written in. Every extension point is documented on one page, and every page has at least one running example: the code on the page is a file in `examples/` that a test suite runs, byte for byte. `composer docs:check` fails when that stops being true.

An extension point is `#[Stable]` or `#[Experimental]`. Every extension point documented here is `#[Experimental]` today: public API an addon may use, without a compatibility promise yet, so it can change in a minor release. `#[Internal]` API is for the kernel alone, and the testkit's PHPStan rules report every use of it from outside `Cbox\Cms`.

- [Contracts](contracts/_index.md): the clock, the id generator, the receipt store and the idempotency store, each with its fake and shared suite.
- [Build declarations](build-declarations.md): scan roots, `#[Command]` and `#[Hook]`, and the registries `cms:build` compiles.
- [Addon manifest](manifest.md): the manifest an addon declares with its namespace, core API version, capabilities, allowed hooks and subscriptions, schema contributions and documentation, and `schema.php`.
- [Hooks](hooks.md): authorize, transform and validate hooks, the classification-filtered view of the plan they get, and their time budgets.
- [Doctor checks](doctor-checks.md): add a check to `cms:doctor` and test it.
- [Blueprint schema v1](blueprint-v1.md): the format of blueprint files, extensions of another owner's type, and addon field types.
- [Testing against real Postgres and Valkey](real-services.md): the testkit's harnesses for tests that need the real services.
- [Static analysis for addons](static-analysis.md): the testkit's PHPStan configuration for an addon.
- [Events](events.md): an event class with its versioned payload, what an event may carry, and the event log that the kernel writes and reads.
- [Subscribers](subscribers.md): a subscriber class with `#[Subscription]`, its lane and the projection it acknowledges on a receipt, and the subscribers registry.
- [Error codes](errors.md): the error catalog, where every error gets its stable code, HTTP status, exit code and MCP response.
- [Commands and write actions](commands.md): `Command`, the `Envelope` a surface builds, `WriteAction` with its aggregates and versions, `#[Action]` and its surfaces, and the `WriteResult` a write ends with.
- [Plans and mutations](plans.md): the `Plan` a write action returns, the typed mutations and the kernel-generic field values of a revision.
- [Queries and query actions](queries.md): `Query`, `QueryAction` and the typed `Result` of a read.
- [Records and JSON codecs](codecs.md): the record DTO and the JSON codec `cms:generate` writes for every type, the JSON form of each kind of value, classification access and `Omitted`, and the `JsonCodec` contract.
- [Runtime validators](validation.md): the validator `cms:generate` writes per type, its rules, and the kernel's `InputValidator`, which checks input from outside against them.
- [Receipt JSON](receipt-json.md): the JSON form of the receipt a write returns, `receipt.v1.json`, and its generated codec.
- [Problem details](problem-details.md): the problem details document (RFC 9457) a surface answers an error with, `problem.v1.json`, and its generated codec.
- [Envelope JSON](envelope-json.md): the envelope fields a caller sends with a write, `envelope.v1.json`, and how a surface builds the `Envelope` from them.
