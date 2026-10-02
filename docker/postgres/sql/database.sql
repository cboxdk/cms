-- One Cbox CMS database with its schema. Run by docker/postgres/initdb.d/10-cms.sh once per
-- database; idempotent.
--
-- The test harness sets up each checkout's own test database, cms_test_<hash of the checkout's
-- path>, with the same statements as the owner role (packages/testkit, TestDatabaseSetup), because
-- the testkit cannot read this file when it is installed on its own.
-- tests/Feature/Tooling/TestDatabaseSetupTest.php keeps the two equal, statement for statement.
--
-- The owner role owns the database, so it has CREATE on it, which is all CREATE EXTENSION of a
-- trusted extension needs: the core's migrations create ltree as the owner role, without a
-- superuser. The schema is the owner's too, so the extension's objects land in it.
--
-- The app role gets CONNECT only: no CREATE (no schemas) and no TEMPORARY on the database,
-- and USAGE without CREATE on the schema, so it cannot run DDL. It gets DML on tables through
-- the owner's default privileges, so a table a migration creates is usable at once. A migration
-- that must narrow that, for example an append-only table, revokes explicitly. The script does
-- not grant on existing tables, so running it again never undoes such a revoke.
--
-- The schema cms_identity holds the credential store of the local accounts (PRD 5.16, "Lokale
-- konti"). The owner role owns it and runs its migrations; the identity role gets CONNECT, USAGE
-- on it and DML on its tables through the owner's default privileges in it. Neither the app role
-- nor PUBLIC gets anything on it, and the app role's default privileges name only the schema
-- above, so the app role cannot read a credential (identity.credential_isolation).

SELECT format('CREATE DATABASE %I OWNER %I', :'db', :'owner_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db')
\gexec

ALTER DATABASE :"db" OWNER TO :"owner_role";
REVOKE ALL ON DATABASE :"db" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"db" TO :"app_role";
GRANT CONNECT ON DATABASE :"db" TO :"identity_role";

\connect :"db"

CREATE SCHEMA IF NOT EXISTS :"schema" AUTHORIZATION :"owner_role";
ALTER SCHEMA :"schema" OWNER TO :"owner_role";
REVOKE ALL ON SCHEMA :"schema" FROM PUBLIC;
GRANT USAGE ON SCHEMA :"schema" TO :"app_role";

-- Postgres 15 and later already deny CREATE on public; this keeps it true on an old volume.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;

ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_role" IN SCHEMA :"schema"
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"app_role";
ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_role" IN SCHEMA :"schema"
    GRANT USAGE, SELECT ON SEQUENCES TO :"app_role";

CREATE SCHEMA IF NOT EXISTS cms_identity AUTHORIZATION :"owner_role";
ALTER SCHEMA cms_identity OWNER TO :"owner_role";
REVOKE ALL ON SCHEMA cms_identity FROM PUBLIC;
GRANT USAGE ON SCHEMA cms_identity TO :"identity_role";

ALTER DEFAULT PRIVILEGES FOR ROLE :"owner_role" IN SCHEMA cms_identity
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO :"identity_role";
