---
title: Postgres roles
weight: 51
description: The operating contract for Postgres, with an app role that owns nothing and an owner role that only the maintenance process holds, and the checks that enforce it.
---

# Postgres roles

The kernel connects to Postgres with two roles, and the operating contract says what each may do (PRD 4.2). `cms:doctor` checks the contract every time it runs, and the checks block the kernel from starting when it is broken.

## The app role

The web and queue processes connect as the app role, on the default connection. The app role:

- is not a superuser, and has `NOBYPASSRLS`, so row level security applies to it, and `NOCREATEROLE`, so it cannot make or grant roles;
- is not a member, directly or through other roles, of a role with more power: a superuser, a role with `BYPASSRLS` or `CREATEROLE`, a role that owns relations or may create objects, or a predefined role that reaches every table or the server's files, signals other sessions or reads their queries;
- owns nothing and cannot create objects in the database or its schemas, so it cannot change the schema, turn off a table's row level security or grant itself more;
- has a `transaction_timeout` above zero, set on the role, so a transaction that hangs ends by itself;
- gets English messages from Postgres, `lc_messages` `C`, because the kernel recognises some errors by their text.

The server runs with `max_prepared_transactions = 0`, so no transaction can be left prepared and hold its locks after the session is gone.

`cms:doctor` checks each of these: `postgres.app_role`, `postgres.ddl_privileges`, `postgres.transaction_timeout`, `postgres.lc_messages` and `postgres.prepared_transactions`.

## Row level security

Every table with row level security also forces it (`FORCE ROW LEVEL SECURITY`), so its policies hold for the table's owner too: a migration or a maintenance job that runs as the owner role reads and writes only the rows the policies allow. `postgres.row_security` checks it. When the partition manager creates a partition, the partition gets its parent's row security flags and grants.

No table of the kernel has row level security yet. The policies come with the command kernel and authorisation in the next milestone; the check and the partition manager already hold the rule for the first table that has them.

## The owner role and the maintenance process

The owner role owns the schema, runs the migrations and runs `cms:partitions:maintain`. Only one type of process holds its credentials: the maintenance process, which runs the migrations on deploy and the scheduler, and serves no HTTP and runs no queue worker. It declares itself with `CBOX_CMS_MAINTENANCE_PROCESS=true` in its own environment.

- A process that serves HTTP or runs queued jobs with the owner connection configured stops while it boots, with `owner_credentials_exposed`, before it runs a request or a job.
- `postgres.owner_credentials` fails in a console process that has the owner connection without the declaration.
- The web and queue processes must not share a configuration cache with the maintenance process.

The three types of process and their connections are described on [cms:doctor](../developers/doctor.md#processes-web-queue-and-maintenance).

## Grants

A migration whose table needs less than the owner's default privileges for the app role, which are `SELECT`, `INSERT`, `UPDATE` and `DELETE`, narrows them. The app role may only read and add receipts and idempotency records, and may only read, add and update projection statuses; it cannot delete any of them.
