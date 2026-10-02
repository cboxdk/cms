#!/usr/bin/env bash
# Creates the Cbox CMS roles, databases and schema (PRD 4.2, GUARDRAILS 6).
#
# Idempotent: the Postgres entrypoint runs it on an empty data volume, and it can be run
# again at any time against a running server to converge an existing volume:
#   docker compose exec postgres /docker-entrypoint-initdb.d/10-cms.sh
#
# CI runs it from outside the server, over TCP (bin/ci with CMS_CI_PROVISION_POSTGRES=1):
# CMS_INIT_PGHOST names the host (PGPORT and PGPASSWORD come from the environment) and
# CMS_INIT_SQL_DIR the directory with roles.sql and database.sql. Without them it uses the
# unix socket and /cms-init, as in the container.
set -euo pipefail

: "${CMS_DATABASES:?}" "${CMS_SCHEMA:?}"
: "${CMS_OWNER_ROLE:?}" "${CMS_OWNER_PASSWORD:?}"
: "${CMS_APP_ROLE:?}" "${CMS_APP_PASSWORD:?}" "${CMS_APP_TRANSACTION_TIMEOUT:?}"
: "${CMS_IDENTITY_ROLE:?}" "${CMS_IDENTITY_PASSWORD:?}"

sql_dir="${CMS_INIT_SQL_DIR:-/cms-init}"

run_psql() {
    # The unix socket, or CMS_INIT_PGHOST, as the superuser. PGHOST and PGDATABASE are cleared
    # so the container's defaults for interactive psql do not leak in. Notices such as
    # "schema already exists, skipping" are expected on a second run and are hidden.
    PGHOST="${CMS_INIT_PGHOST:-}" PGHOSTADDR='' PGDATABASE='' PGOPTIONS='-c client_min_messages=warning' psql \
        --no-psqlrc --quiet --set=ON_ERROR_STOP=1 \
        --username="${POSTGRES_USER:-postgres}" --dbname=postgres \
        --set=schema="$CMS_SCHEMA" \
        --set=owner_role="$CMS_OWNER_ROLE" --set=owner_password="$CMS_OWNER_PASSWORD" \
        --set=app_role="$CMS_APP_ROLE" --set=app_password="$CMS_APP_PASSWORD" \
        --set=app_transaction_timeout="$CMS_APP_TRANSACTION_TIMEOUT" \
        --set=identity_role="$CMS_IDENTITY_ROLE" --set=identity_password="$CMS_IDENTITY_PASSWORD" \
        "$@"
}

run_psql --file="$sql_dir/roles.sql"

for database in $CMS_DATABASES; do
    run_psql --set=db="$database" --file="$sql_dir/database.sql"
done

echo "cms: roles ${CMS_OWNER_ROLE}, ${CMS_APP_ROLE} and ${CMS_IDENTITY_ROLE}, schemas ${CMS_SCHEMA} and cms_identity in: ${CMS_DATABASES}"
