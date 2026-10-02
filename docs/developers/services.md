---
title: Services and isolation
weight: 25
description: The shared Postgres and Valkey services of the development environment, the roles and databases on them, and how every checkout and worktree gets its own test database and Valkey prefix.
---

# Services and isolation

## One set of services per machine

`compose.yaml` in the main checkout defines the services, and `composer services:up` starts them. Every checkout of the repository on the machine uses the same Postgres and Valkey: the main checkout and every git worktree of it. `composer services:up` always runs `docker compose` with the main checkout's `compose.yaml` and the main checkout as the project directory, so no container ever mounts a worktree.

- **From the main checkout**, `composer services:up` starts all three services and waits until they are healthy, and `composer services:down` stops them and keeps the data volumes.
- **From a worktree**, `composer services:up` starts only Postgres and Valkey, never recreates a running container, and says that the php container mounts the main checkout. The gates of a worktree run in a container of their own that mounts the worktree and joins the services' network ([The dev image](gates-and-ci.md#the-dev-image)). `composer services:down` refuses, because other checkouts use the services.

Both run the Postgres init script afterwards. It is idempotent, so an existing data volume gets the current roles and databases.

## The roles and databases

The init script creates three login roles and two databases, `cms` for the workbench and `cms_test` as the base of the test databases:

| Role | What it does | What it may do |
|---|---|---|
| `cms_owner` | Owns the databases and the `cms` and `cms_identity` schemas, runs the migrations and partition maintenance, creates the test databases. | `CREATEDB`; not a superuser, `NOCREATEROLE`, `NOBYPASSRLS`. |
| `cms_app` | The application and the tests. | Not a superuser, `NOCREATEDB`, `NOCREATEROLE`, `NOBYPASSRLS`. It owns nothing and has no DDL, and a transaction ends after 5 seconds (`transaction_timeout`). |

| `cms_identity` | The credential store of the local accounts, on the connection `pgsql_identity`. | Not a superuser, `NOCREATEDB`, `NOCREATEROLE`, `NOBYPASSRLS`. It owns nothing, and reads and writes the tables of the schema `cms_identity` only, which no other role but the owner may touch (see [Credential store](../security/credential-store.md)). |

The roles have `lc_messages = 'C'`. The owner and the app role have the search path `cms`, and the identity role `cms_identity`; the workbench's owner connection lists `cms_identity` after `cms`. The server runs with `max_prepared_transactions = 0`. These are the development credentials; [Postgres roles](../security/postgres-roles.md) describes the contract they follow.

## A test database per checkout

The Postgres suite of every checkout runs in a database of its own. The name is the configured database, `_`, and the first 12 hex digits of the SHA-256 of the checkout's real path, for example `cms_test_3f9a0c21d4e7`. The same path always gives the same name, and another path another name. The testkit creates the database as the owner role the first time a process needs it, sets it up for the app role, and runs the migrations once per process. The database carries a comment that names the host and the checkout's path.

After a worktree is removed by hand, its test database stays. `composer test-db:prune` drops the test databases whose checkout on this host is gone, also while a session is still connected, and keeps everything else: the configured database, this checkout's, other hosts' and those without a valid comment. `composer test-db:prune -- --dry-run` prints the same verdicts and drops nothing. Without the `--`, Composer drops the option and the prune is real.

## A Valkey prefix per run

The tests use Valkey database 15, and every PHP process of a test run writes under a prefix of its own, `cms_test_<run id>_`, so two runs never see each other's keys. After each test, and when the process ends, the testkit removes the keys under the prefix with `SCAN` and `UNLINK`, never `FLUSHDB`, so the keys of other runs stay.

## The dev database

`cms` is the workbench's database and is shared by every checkout. `composer dev:prepare` migrates it and creates its partitions; run it from the main checkout only.
