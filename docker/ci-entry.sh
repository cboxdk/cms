#!/usr/bin/env bash
# The entry point of the ci service in compose.ci.yaml: runs bin/ci on a clean git archive of
# HEAD, never on the working tree.
#
# The repository's .git directory is mounted read-only at CMS_CI_SOURCE (default /source.git).
# The script exports HEAD with git archive into CMS_CI_WORK (default /work), commits it there as
# the only commit of a new repository, as a CI checkout is a git repository (gate 6 compares with
# git), and runs the command it was given, bin/ci by default. An uncommitted change or an
# untracked file in the working tree therefore never reaches the run.
set -euo pipefail

source_git="${CMS_CI_SOURCE:-/source.git}"
work="${CMS_CI_WORK:-/work}"

if [[ $# -eq 0 ]]; then
    set -- bin/ci
fi

commit="$(git -c safe.directory='*' --git-dir="$source_git" rev-parse --verify 'HEAD^{commit}')"

rm -rf "$work"
mkdir -p "$work"
git -c safe.directory='*' --git-dir="$source_git" archive --format=tar "$commit" | tar -x -C "$work"

cd "$work"
git -c init.defaultBranch=main init --quiet
# Every file came from the archive, so every file was tracked: add ignored ones too.
git add --all --force
git -c user.name='bin/ci' -c user.email='ci@localhost.invalid' -c commit.gpgsign=false -c core.hooksPath=/dev/null \
    commit --quiet --message="HEAD ${commit} of the mounted repository"

echo "ci-entry: HEAD ${commit} archived to ${work}"

exec "$@"
