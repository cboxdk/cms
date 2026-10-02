---
title: Postgres roles
weight: 51
description: The operating contract for Postgres, with an app role that owns nothing and an owner role that only the maintenance process holds, and the checks that enforce it.
---

# Postgres roles

The kernel connects to Postgres with two roles, and the operating contract says what each may do (PRD 4.2). The identity module adds a third, the identity role, which alone reaches the credentials of the local accounts; see [Credential store](credential-store.md). `cms:doctor` checks the contract every time it runs, and the checks block the kernel from starting when it is broken.

## The app role

The web and queue processes connect as the app role, on the default connection. The app role:

- is not a superuser, and has `NOBYPASSRLS`, so row level security applies to it, and `NOCREATEROLE`, so it cannot make or grant roles;
- is not a member, directly or through other roles, of a role with more power: a superuser, a role with `BYPASSRLS` or `CREATEROLE`, a role that owns relations or may create objects, or a predefined role that reaches every table or the server's files, signals other sessions or reads their queries;
- owns nothing and cannot create objects in the database or its schemas, so it cannot change the schema, turn off a table's row level security or grant itself more;
- has a `transaction_timeout` above zero, set on the role, so a transaction that hangs ends by itself;
- has an `idle_in_transaction_session_timeout` above zero, set on the role, so a session that begins a transaction and then waits is ended as well;
- gets English messages from Postgres, `lc_messages` `C`, because the kernel recognises some errors by their text.

The server runs with `max_prepared_transactions = 0`, so no transaction can be left prepared and hold its locks after the session is gone.

`cms:doctor` checks each of these: `postgres.app_role`, `postgres.ddl_privileges`, `postgres.transaction_timeout`, `postgres.idle_in_transaction_timeout`, `postgres.lc_messages` and `postgres.prepared_transactions`.

## Row level security

Every table with row level security also forces it (`FORCE ROW LEVEL SECURITY`), so its policies hold for the table's owner too: a migration or a maintenance job that runs as the owner role reads and writes only the rows the policies allow. `postgres.row_security` checks it. When the partition manager creates a partition, the partition gets its parent's row security flags and grants.

Every policy tests the actor context, which the kernel sets with `SET LOCAL` inside the transaction of each command and read, reads included, so a pooler in transaction mode never hands it to another client. Without a context the app role reads no row of any table with row level security and writes none; `tests/Postgres/WalkingSkeleton/RlsWithoutActorContextTest.php` lists every such table from `pg_class`, partitions and generated type tables included, and checks it. The context names the principal (an actor or the anonymous context), the actor's access regions and their exceptions as ltree paths, and its classification access. Row level security is the backstop, not the filter: the queries add the same predicates themselves (PRD 5.10).

- An actor reaches the nodes its regions reach. An entry is reached through its home node, with `EXISTS` against `nodes`, or by the actor that owns it; its variant heads, revisions, payloads, head snapshots and release log rows follow the entry. Placement generations and placement locales are reached through the placement's node, because placements are managed where they sit.
- Every context, the anonymous one included, reads what is public: the released stage of placements that are live, and the active entries, released variant heads, published revisions and payloads and head snapshots behind them. The anonymous context reads nothing else and writes nothing.
- An actor writes, inserts and reads its own changesets, their on-behalf-of chains and its own audit row. It reads a reason text of a changeset it sees only up to its classification access. The audit has no read policy yet.
- Roles and grants are written only by the owner role. An actor reads every role and its own grants, and the nodes those grants name, so the kernel can compile its regions.
- A generated type table gets the same two policies over its system columns `cms_home_node`, `cms_owner_actor`, `cms_entry_id` and `cms_stage` (`TypeTableAccess`).
- Sites, site locales, node routes and mount overrides have no policy yet, so they stay closed to the app role and to the owner role alike.

The identity tables have a policy for the owner role alone. The credential verifier and the actor directory run before a context exists, so they read one row by its key through three lookup functions, `cms_identity_credential`, `cms_identity_delegations` and `cms_identity_actor`, which run as the owner role (`SECURITY DEFINER`, with a fixed `search_path`) and never list the table.

The app role keeps only what the kernel needs to write: SELECT and INSERT on the changeset, revision and release log tables, UPDATE on `head_snapshots` alone, because a changeset, a revision, a payload and a release are never changed once written, SELECT on roles and grants, and SELECT and INSERT on the audit. The free text of a reason can name people, so it lives in `changeset_reason_texts` as classified content, apart from the changeset's metadata, and never in the audit or an event. The audit holds one row per changeset of ids, codes and enums, no text.

## Extensions

The node tree keeps its paths in ltree, an extension that ships with Postgres. The core's migrations create it as the owner role. ltree is a trusted extension, so the owner role needs no superuser to create it, only `CREATE` on the database, which it has as the database's owner. `postgres.extensions` fails with `doctor_extension_missing` while the database lacks it.

## The owner role and the maintenance process

The owner role owns the schema, runs the migrations and runs `cms:partitions:maintain`. Only one type of process holds its credentials: the maintenance process, which runs the migrations on deploy and the scheduler, and serves no HTTP and runs no queue worker. It declares itself with `CBOX_CMS_MAINTENANCE_PROCESS=true` in its own environment.

- A process that serves HTTP or runs queued jobs with the owner connection configured stops while it boots, with `owner_credentials_exposed`, before it runs a request or a job.
- `postgres.owner_credentials` fails in a console process that has the owner connection without the declaration.
- The web and queue processes must not share a configuration cache with the maintenance process.

The three types of process and their connections are described on [cms:doctor](../developers/doctor.md#processes-web-queue-and-maintenance).

## Grants

A migration whose table needs less than the owner's default privileges for the app role, which are `SELECT`, `INSERT`, `UPDATE` and `DELETE`, narrows them. The app role may only read and add receipts and idempotency records, and may only read, add and update projection statuses; it cannot delete any of them. The structure and entry tables give it `SELECT`, `INSERT` and `UPDATE`, and the identity tables `SELECT` alone.
