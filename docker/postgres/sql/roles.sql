-- Cluster roles for Cbox CMS. Run by docker/postgres/initdb.d/10-cms.sh; idempotent.
--
-- owner: owns the databases and the schema. Runs migrations and partition maintenance.
--   CREATEDB, because the test harness creates each checkout's own test database as the owner,
--   cms_test_<hash of the checkout's path> (packages/testkit, TestDatabase). This file is for
--   development, tests and CI only; a production owner role needs no CREATEDB.
--   Member of pg_signal_backend, because `composer test-db:prune` drops the test databases of
--   removed checkouts with DROP DATABASE ... WITH (FORCE), which terminates the sessions still
--   connected to them, also those of the app role. Also for development, tests and CI only; a
--   production owner role needs no pg_signal_backend.
--   No transaction_timeout: index migrations run without a wrapping transaction
--   (PRD 4.2, "Indeks-DDL") and CREATE INDEX CONCURRENTLY can run for a long time.
-- app: the application and the tests. Owns nothing, has no DDL and NOBYPASSRLS
--   (GUARDRAILS 6), and every transaction is capped at the command budget (GUARDRAILS 4.1).
--   idle_in_transaction_session_timeout at the same budget, so a session that begins a
--   transaction and then waits is ended as well (PRD 7.4, postgres.idle_in_transaction_timeout).
-- Both: lc_messages = 'C', so their server messages are English whatever the server's default
--   (PRD 4.2); the kernel reads the text of some errors. lc_messages is superuser-only, so it
--   is set here, by the superuser that runs the script.

SELECT format('CREATE ROLE %I', :'owner_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'owner_role')
\gexec

ALTER ROLE :"owner_role" WITH LOGIN NOSUPERUSER CREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
    PASSWORD :'owner_password';
ALTER ROLE :"owner_role" SET search_path = :"schema";
ALTER ROLE :"owner_role" RESET transaction_timeout;
ALTER ROLE :"owner_role" SET lc_messages = 'C';
GRANT pg_signal_backend TO :"owner_role";

SELECT format('CREATE ROLE %I', :'app_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_role')
\gexec

ALTER ROLE :"app_role" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
    PASSWORD :'app_password';
ALTER ROLE :"app_role" SET search_path = :"schema";
ALTER ROLE :"app_role" SET transaction_timeout = :'app_transaction_timeout';
ALTER ROLE :"app_role" SET idle_in_transaction_session_timeout = :'app_transaction_timeout';
ALTER ROLE :"app_role" SET lc_messages = 'C';
