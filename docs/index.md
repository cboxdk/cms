---
title: Cbox CMS
weight: 1
description: What Cbox CMS is, how its kernel is put together, what exists today, and where each part of the documentation is.
---

# Cbox CMS

Cbox CMS is a CMS for Laravel, built on PHP 8.5, Laravel 13, Postgres and Valkey. It is in development: this repository holds the kernel, the tools that build and check it, and a workbench application to run it in. There is no release yet.

## The mental model

- **The kernel knows no content types.** Every type, field and capability is defined in blueprint files, YAML documents that follow the published JSON Schema [`blueprint.v1.json`](addons/blueprint-v1.md). `cms:generate` reads them and writes generated code: today a PHP enum of the type handles with their fields, and the matching TypeScript types.
- **Every write is a command.** A command runs through one pipeline in one database transaction and commits one changeset. Its result is a receipt that says what was committed and which projections have caught up, and an idempotency key makes a repeated call return the first result instead of running again. The receipt store and the idempotency store exist today; the pipeline that uses them comes with the next milestone.
- **The kernel depends on contracts, not classes.** The clock, the id generator and the stores are interfaces in the contracts module of `cboxdk/cms`. The container binds each one to a default that an application can replace, and the testkit has a fake and a shared test suite for each, so a replacement proves it keeps the same promises.
- **Postgres runs under an operating contract.** The application connects as a role that owns nothing and cannot change the schema. A separate owner role runs the migrations and the partition maintenance, in a process of its own. Tables that grow with time are partitioned by range and kept by a partition manager.
- **Extensions are declared, then compiled.** A package marks its commands and hooks with attributes. `cms:build` reads them once and compiles registries, so nothing is discovered with reflection while a request runs.
- **The installation checks itself.** `cms:doctor` checks PHP, Laravel, Postgres, Valkey, the partitions and the registry, and says for every problem what is wrong and how to fix it.

## What exists today

The first milestone built the toolchain and the foundations the kernel stands on:

- the contracts `Clock`, `IdGenerator`, `ReceiptStore` and `IdempotencyStore`, with their default implementations, fakes and shared suites;
- the receipt and idempotency stores on Postgres, and the partition manager with `cms:partitions:maintain`;
- the blueprint schema v1, its reader, `cms:generate` and `cms:schema:editor`;
- the registry of commands and hooks, compiled by `cms:build`;
- `cms:doctor` with its checks, exit codes and JSON document;
- the gates every change passes, `composer check` locally and `bin/ci` in CI.

The command pipeline, the event log, delivery, the surfaces and the panel come with the next milestones. The documentation describes only what the code does today.

## Sections

- [Quickstart](quickstart.md): from a clone to a green `composer check` and `cms:doctor`.
- [Requirements](requirements.md): the versions Composer enforces and the services `cms:doctor` checks.
- [Getting started](getting-started/_index.md): the development environment, and testing with the testkit's fakes.
- [Developers](developers/_index.md): the architecture and its layers, the gates and CI, `cms:doctor`, partitions, the services and the configuration.
- [Addons](addons/_index.md): the extension points: the contracts, build declarations, doctor checks, the blueprint schema, and the testkit for an addon's own tests.
- [Security](security/_index.md): the operating contract for the Postgres roles, the rule for outbound requests, and what the kernel does not protect yet.
- [Reference](reference/_index.md): pages generated from the code, such as the error reference with every error code.
- [Screenshots](screenshots/_index.md): the terminal output the pages show, and how it is captured.
