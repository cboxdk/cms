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
- [Addon field types](field-types.md): a field type `<namespace>:<handle>` an addon contributes, the JSON Schema of its options, and the shape every generator writes a field of it as.
- [Panel UI](panel/_index.md): how an addon extends the control panel at its declared points, the trust model it runs under, the SDK, the bundle, testing and stability, with a page per [kind of contribution](panel/kinds/_index.md) and per [panel point](panel/points/_index.md).
- [Hooks](hooks.md): authorize, transform and validate hooks, the classification-filtered view of the plan they get, and their time budgets.
- [Doctor checks](doctor-checks.md): add a check to `cms:doctor` and test it.
- [Blueprint schema v1](blueprint-v1.md): the format of blueprint files, extensions of another owner's type, and addon field types.
- [Testing against real Postgres and Valkey](real-services.md): the testkit's harnesses for tests that need the real services.
- [Static analysis for addons](static-analysis.md): the testkit's PHPStan configuration for an addon.
- [Events](events.md): an event class with its versioned payload, what an event may carry, and the event log that the kernel writes and reads.
- [Subscribers](subscribers.md): a subscriber class with `#[Subscription]`, its lane and the projection it acknowledges on a receipt, and the subscribers registry.
- [Error codes](errors.md): the error catalog, where every error gets its stable code, HTTP status, exit code and MCP response.
- [Commands and write actions](commands.md): `Command`, the `Envelope` a surface builds, `WriteAction` with its aggregates and versions, `#[Action]` and its surfaces, and the `WriteResult` a write ends with.
- [Entry commands](entry-commands.md): `entry.create` and `entry.revise`, what the kernel stores for them as a type's capabilities say, their events and their rejections.
- [Placement commands](placement-commands.md): `placement.create` and `placement.set_window`, where a placement is decided, the slug and canonical rules, the visibility states, their events and their rejections.
- [Release command](release-command.md): `variant.release`, what the kernel checks before a revision is released, what it stores, its event and its rejections.
- [Publish commands](publish-commands.md): `entry.publish` and `entry.unpublish`, the release and the home placement going live in one changeset, what a dry run shows, what unpublishing takes back, their events and their rejections.
- [Actor commands](actor-commands.md): `actor.register`, `actor.activate` and `actor.deactivate`, the order of a registration, the actor's profile as personal data, their events, and how a deactivation stops every command and read of the actor.
- [Grant commands](grant-commands.md): `grant.assign` and `grant.revoke`, the escalation guard that keeps an actor from giving more than it holds, step-up for administrative roles, `grant.changed` and their rejections.
- [Role commands](role-commands.md): `role.create` and `role.set_permissions`, the escalation guard on a role's content, step-up for a role that becomes administrative, `grant.changed` for every holder and their rejections.
- [Site commands](site-commands.md): `cms:sites:sync` and `site.register`, which put the sites of `cbox-cms.sites` in the database with their root nodes and locales, why a drift of a site's locales is reported and not rewritten, and their rejections.
- [Plans and mutations](plans.md): the `Plan` a write action returns, the typed mutations and the kernel-generic field values of a revision.
- [Queries and query actions](queries.md): `Query`, `QueryAction` and the typed `Result` of a read.
- [Access queries](access-queries.md): `role.list`, `grant.list`, `actor.list` and `node.list`, the pages they read, who may run them, and the profiles they leave out below personal access.
- [Records and JSON codecs](codecs.md): the record DTO and the JSON codec `cms:generate` writes for every type, the JSON form of each kind of value, classification access and `Omitted`, and the `JsonCodec` contract.
- [Runtime validators](validation.md): the validator `cms:generate` writes per type, its rules, and the kernel's `InputValidator`, which checks input from outside against them.
- [Dry run JSON](dry-run-json.md): the JSON form of what a dry run reports, the blast radius, each aggregate's version change and what becomes visible, and its generated codec.
- [Receipt JSON](receipt-json.md): the JSON form of the receipt a write returns, `receipt.v1.json`, and its generated codec.
- [Problem details](problem-details.md): the problem details document (RFC 9457) a surface answers an error with, `problem.v1.json`, and its generated codec.
- [Envelope JSON](envelope-json.md): the envelope fields a caller sends with a write, `envelope.v1.json`, and how a surface builds the `Envelope` from them.
- [Command JSON](command-json.md): the JSON form of each of the kernel's commands, one schema per command and version, the generic fields of a revision, and the generated codecs every surface reads them with.
- [Delivery and explanation JSON](delivery-json.md): the JSON forms of the delivery API's answers and fragments, the path explanation and `cms:explain --json`, documents embedded in documents, and their generated codecs.
