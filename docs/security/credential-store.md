---
title: Credential store
weight: 52
description: Where the local accounts keep their credentials, why the app role cannot read them, and how an operator creates the identity role, the schema and the connection in production.
---

# Credential store

A person who logs in with a local account has a credential: a login and a password hash. The kernel keeps those credentials apart from everything else it stores (PRD 5.16, "Lokale konti"). They live in a Postgres schema of their own, `cms_identity`, reached only by a role of its own, the identity role, on a database connection of its own. The app role, which the web and queue processes use for everything else, has no privilege on the schema or its tables, so a bug or an injection in code that runs as the app role cannot read a password hash.

The isolation is by privilege, not by row level security: the tables of `cms_identity` have no policies, because no role but the identity role and the owner may touch them at all.

The identity module, `Cbox\Cms\Identity`, owns the store. Its migrations create the tables as the owner role:

- `cms_identity.local_accounts` holds one local account per actor: the actor's id, which refers to `actors`, so there is one list of people; the login, lowercased and unique; the Argon2id hash of the password; when the password was changed; a version; and when the account was made. A CHECK holds the form of every column.
- `cms_identity.password_reset_tokens` holds a reset token of a local account as the SHA-256 of the token, never the token itself, with when it expires and when it was used.
- `cms_identity.idp_links` holds the links of actors to IdP identities (PRD 5.16, "Koblinger"): an IdP identity, its connection, issuer and subject, is the key and points at one actor in `actors`. The [login policy](login-policy.md) reads them to refuse a local login of an actor linked to an authoritative connection. The federated connections and SCIM write them; they come with B1 part 2.

The module's [LocalCredentialStore](../addons/contracts/local-credential-store.md), `PostgresLocalCredentialStore`, reads and writes the accounts and the reset tokens on the identity connection; [Local accounts](local-accounts.md) says how a member of staff gets one.

No code outside the identity module writes these tables: the testkit's PHPStan rule `cboxCms.kernelTableWrite` reports a write to a table of `cms_identity` anywhere else, the core included.

## What cms:doctor checks

The identity module adds three blocking checks to `cms:doctor` (see [cms:doctor](../developers/doctor.md#which-checks-run)):

- `identity.connection` connects on the identity connection and fails with `doctor_identity_connection_shared_role` when it logs in as the app role or as the owner role, because the store is then not isolated from the role that uses it for other work. It fails with `doctor_identity_connection_unavailable` when Postgres does not answer and `doctor_identity_connection_refused` when it refuses the login.
- `identity.credential_isolation` fails with `doctor_credential_store_missing` when the schema does not exist, with `doctor_credential_store_readable` when the app role holds any privilege on the schema or a relation in it, directly, through a role it is a member of or through `PUBLIC`, and with `doctor_identity_role_privileged` when the identity role is a superuser, has `BYPASSRLS` or `CREATEROLE`, or is a member of a role with more power, as `postgres.app_role` judges memberships.
- `identity.argon2id` fails with `doctor_argon2id_unavailable` when PHP cannot hash passwords with Argon2id.

## Setting it up in production

In development, tests and CI, `docker/postgres/sql/roles.sql` and `docker/postgres/sql/database.sql` create the identity role `cms_identity` and the schema, and `composer services:up` runs them again on an existing volume. Those files are for development only: their passwords are fixed. In production the operator creates the role, the schema and the connection, once per database, before the migrations run. The names below are those of the development setup; `cms_owner` is the owner role, `cms_app` the app role and `cms` the database.

1. As a superuser, create the role with a password of its own, without any power beyond logging in: `CREATE ROLE cms_identity LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD '<secret>';`
2. Give it the store's schema as its search path and the same limits as the app role: `ALTER ROLE cms_identity SET search_path = cms_identity;`, `ALTER ROLE cms_identity SET transaction_timeout = '5s';`, `ALTER ROLE cms_identity SET idle_in_transaction_session_timeout = '5s';` and `ALTER ROLE cms_identity SET lc_messages = 'C';`.
3. Let it connect to the database: `GRANT CONNECT ON DATABASE cms TO cms_identity;`.
4. Connected to the database, create the schema owned by the owner role and take every privilege on it away from `PUBLIC`: `CREATE SCHEMA IF NOT EXISTS cms_identity AUTHORIZATION cms_owner;` and `REVOKE ALL ON SCHEMA cms_identity FROM PUBLIC;`.
5. Give the identity role, and no other role, the schema and the tables the migrations will create in it: `GRANT USAGE ON SCHEMA cms_identity TO cms_identity;` and `ALTER DEFAULT PRIVILEGES FOR ROLE cms_owner IN SCHEMA cms_identity GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO cms_identity;`.
6. Grant the app role nothing in `cms_identity`, and keep the default privileges that give it the kernel's tables limited to the kernel's schema, as `ALTER DEFAULT PRIVILEGES ... IN SCHEMA cms` does.

Then add the connection to `config/database.php`: a copy of the default `pgsql` connection, to the same host and database, with the identity role's username and password, such as `env('DB_IDENTITY_USERNAME')` and `env('DB_IDENTITY_PASSWORD')`, and `'search_path' => 'cms_identity'`. Name it `pgsql_identity`, or set `cbox-cms.identity.connection` to its name (see [Configuration](../developers/configuration.md#identity)). The web processes need it, because they serve the login pages; the identity role's password is a secret like the app role's.

In the owner connection, which only the maintenance process has, list the store's schema after the kernel's in the search path, such as `'search_path' => 'cms,cms_identity'`. The migrations qualify the store's tables by their schema, so `migrate` works without it, but `migrate:fresh` drops only the tables of the schemas in the search path and would otherwise leave the store behind.

Run the migrations as the owner role, then `cms:doctor` in every type of process. All three identity checks must pass before the kernel starts.
