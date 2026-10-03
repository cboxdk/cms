#!/usr/bin/env bash
# Sets up the CI environment on top of ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1,
# the PHP 8.5 image of the v1 channel. It runs as root in the root of the checkout, before bin/ci:
# .github/workflows/ci.yml runs it as the step before bin/ci in its job container, and
# docker/ci-entry.sh, the entry point of compose.ci.yaml's ci service, runs it from the archive of
# HEAD before bin/ci. So bin/ci runs on the same setup in both places, and it is the setup of the
# commit that is checked.
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
# - PCOV: mutation on changed files in gate 5 needs a coverage driver. The image loads the
#   extension and leaves it off; tools/mutation/pcov.ini turns it on for the mutation step. The
#   script fails when the extension is not loaded, so the step never runs without coverage.
# - Chromium, Firefox and WebKit for the Playwright that package-lock.json pins, for the Browser
#   suite of gate 8, which runs in Chromium and runs its group browser-matrix in Firefox and WebKit
#   too. The image keeps its browsers in PLAYWRIGHT_BROWSERS_PATH (/ms-playwright), where the user
#   ci finds them too. The pinned Playwright names the builds it needs, Chromium and its headless
#   shell, Firefox and WebKit, each in a directory named after its revision. A build the image has
#   is used as it is; the missing ones are installed with the pinned Playwright, with the system
#   libraries they need (the image ships Chromium only, and WebKit needs GTK and GStreamer), and
#   the script fails when a build is still missing afterwards, so gate 8 never runs a browser that
#   Playwright was not built for.
set -euo pipefail

required_node_major=22
node_major="$(node --version | sed -E 's/^v([0-9]+)\..*$/\1/')"

if [[ "$node_major" != "$required_node_major" ]]; then
    echo "ci-setup: Node ${required_node_major} is required, the image has $(node --version)." >&2
    exit 1
fi

if ! php -r 'exit(extension_loaded("pcov") ? 0 : 1);'; then
    echo 'ci-setup: the PHP extension pcov is not loaded. Mutation on changed files in gate 5 needs it for code coverage; tools/mutation/pcov.ini turns it on for the step.' >&2
    exit 1
fi

if [[ ! -f package-lock.json ]]; then
    echo "ci-setup: run it in the root of the checkout; there is no package-lock.json in ${PWD}." >&2
    exit 1
fi

if [[ -z "${PLAYWRIGHT_BROWSERS_PATH:-}" ]]; then
    echo 'ci-setup: PLAYWRIGHT_BROWSERS_PATH is not set. The image keeps its browsers there, and without it Playwright would install them below the home directory of root, where the user ci cannot use them.' >&2
    exit 1
fi

playwright_version="$(node -e '
    const lock = require(process.argv[1]);
    const entry = (lock.packages || {})["node_modules/playwright"];
    if (!entry || typeof entry.version !== "string") {
        process.exit(1);
    }
    process.stdout.write(entry.version);
' "$PWD/package-lock.json")" || {
    echo 'ci-setup: package-lock.json locks no version of playwright.' >&2
    exit 1
}

export DEBIAN_FRONTEND=noninteractive
apt-get update --quiet
apt-get install --quiet --yes --no-install-recommends postgresql-client
rm -rf /var/lib/apt/lists/*

git config --system --add safe.directory '*'

if ! getent passwd ci >/dev/null; then
    useradd --uid 1001 --user-group --create-home --shell /bin/bash ci
fi

# The pinned Playwright in a directory of its own, outside the checkout, so bin/ci's npm ci
# still installs node_modules from the lock file alone.
playwright_dir="$(mktemp -d)"
trap 'rm -rf "$playwright_dir"' EXIT
npm install --prefix "$playwright_dir" --cache "$playwright_dir/.npm" --no-save --no-audit --no-fund \
    --no-update-notifier --ignore-scripts --loglevel=error "playwright@${playwright_version}" >/dev/null
playwright="$playwright_dir/node_modules/.bin/playwright"

# The browsers gate 8 runs in, as Playwright names them.
browsers=(chromium firefox webkit)

# The install directories of the builds `playwright install` needs for them, from its dry run.
browser_builds() {
    (cd "$playwright_dir" && "$playwright" install --dry-run "${browsers[@]}") |
        sed -n -E 's/^[[:space:]]*Install location:[[:space:]]+(.*(chromium|chromium_headless_shell|firefox|webkit)-[0-9]+)[[:space:]]*$/\1/p'
}

missing_builds() {
    local builds

    if ! builds="$(browser_builds)" || [[ -z "$builds" ]]; then
        echo "ci-setup: Playwright ${playwright_version} names no browser build to install." >&2
        return 2
    fi

    while IFS= read -r build; do
        [[ -f "$build/INSTALLATION_COMPLETE" ]] || echo "$build"
    done <<<"$builds"
}

missing="$(missing_builds)"

if [[ -n "$missing" ]]; then
    echo "ci-setup: the image lacks the browser builds of Playwright ${playwright_version}: ${missing//$'\n'/ }"
    (cd "$playwright_dir" && "$playwright" install --with-deps "${browsers[@]}")
    missing="$(missing_builds)"

    if [[ -n "$missing" ]]; then
        echo "ci-setup: Playwright ${playwright_version} needs browser builds that are still missing after the install: ${missing//$'\n'/ }" >&2
        exit 1
    fi
fi

echo "ci-setup: PHP $(php -r 'echo PHP_VERSION;') with pcov, Node $(node --version), $(psql --version), $(git --version)"
builds="$(browser_builds)"
echo "ci-setup: Playwright ${playwright_version} with Chromium, Firefox and WebKit in ${builds//$'\n'/ }"
