#!/usr/bin/env bash
# Sets up the CI environment on top of ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1,
# the PHP 8.5 image of the v1 channel. docker/ci.Dockerfile runs it to build the ci image of
# compose.ci.yaml, and .github/workflows/ci.yml runs it in its job container, which is the same
# base image. So bin/ci runs on the same setup in both places.
#
# - Node 22: the base image ships it. The script checks the major version and does not install
#   a second Node.
# - psql: bin/ci creates the roles and databases on a CI Postgres service with
#   docker/postgres/initdb.d/10-cms.sh over TCP (CMS_CI_PROVISION_POSTGRES=1).
# - git: a CI checkout belongs to another user than the one running git, so git trusts every
#   directory.
# - the user ci, uid 1001: bin/ci runs the gates as ci, never as root. Root ignores file
#   permissions, so the tests of unwritable files skip, and gate 5 fails a skipped test. 1001 is
#   the uid of GitHub's runner user, so the files the runner hands the job stay writable.
set -euo pipefail

required_node_major=22
node_major="$(node --version | sed -E 's/^v([0-9]+)\..*$/\1/')"

if [[ "$node_major" != "$required_node_major" ]]; then
    echo "ci-setup: Node ${required_node_major} is required, the image has $(node --version)." >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update --quiet
apt-get install --quiet --yes --no-install-recommends postgresql-client
rm -rf /var/lib/apt/lists/*

git config --system --add safe.directory '*'

if ! getent passwd ci >/dev/null; then
    useradd --uid 1001 --user-group --create-home --shell /bin/bash ci
fi

echo "ci-setup: PHP $(php -r 'echo PHP_VERSION;'), Node $(node --version), $(psql --version), $(git --version)"
