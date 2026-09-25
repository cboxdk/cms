-- Cluster roles for Cbox CMS. Run by docker/postgres/initdb.d/10-cms.sh; idempotent.
--
-- owner: owns the databases and the schema. Runs migrations and partition maintenance.
--   No transaction_timeout: index migrations run without a wrapping transaction
--   (PRD 4.2, "Indeks-DDL") and CREATE INDEX CONCURRENTLY can run for a long time.
-- app: the application and the tests. Owns nothing, has no DDL and NOBYPASSRLS
--   (GUARDRAILS 6), and every transaction is capped at the command budget (GUARDRAILS 4.1).

SELECT format('CREATE ROLE %I', :'owner_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'owner_role')
\gexec

ALTER ROLE :"owner_role" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
    PASSWORD :'owner_password';
ALTER ROLE :"owner_role" SET search_path = :"schema";
ALTER ROLE :"owner_role" RESET transaction_timeout;

SELECT format('CREATE ROLE %I', :'app_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'app_role')
\gexec

ALTER ROLE :"app_role" WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS
    PASSWORD :'app_password';
ALTER ROLE :"app_role" SET search_path = :"schema";
ALTER ROLE :"app_role" SET transaction_timeout = :'app_transaction_timeout';
