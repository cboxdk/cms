#!/usr/bin/env bash
# The entry point of the ci service in compose.ci.yaml: runs bin/ci on a clean git archive of
# HEAD, never on the working tree.
#
# The repository's .git directory is mounted read-only at CMS_CI_SOURCE (default /source.git).
# The script exports HEAD with git archive into CMS_CI_WORK (default /work) and commits it there
# in a new repository, as a CI checkout is a git repository (gate 6 compares with git), and runs
# the command it was given. Without a command it runs what the job of .github/workflows/ci.yml
# runs after its checkout: docker/ci-setup.sh and then bin/ci, both from the archive. An
# uncommitted change or an untracked file in the working tree therefore never reaches the run.
#
# CMS_CI_BASE_REF is the base of the change for mutation on changed files in gate 5, as the pull
# request's base commit is in ci.yml. The script resolves it in the mounted repository and builds
# the new repository as two commits: the tree of the merge base of CMS_CI_BASE_REF and HEAD, then
# the tree of HEAD. Inside, CMS_CI_BASE_REF is HEAD~1, so the step mutates what changed since the
# merge base. A ref that names no commit, or has no merge base with HEAD, stops the script before
# anything runs. Without CMS_CI_BASE_REF there is one commit, the variable stays unset, and the
# mutation step fails for want of a base.
set -euo pipefail

source_git="${CMS_CI_SOURCE:-/source.git}"
work="${CMS_CI_WORK:-/work}"

source_repository() {
    git -c safe.directory='*' --git-dir="$source_git" "$@"
}

commit="$(source_repository rev-parse --verify 'HEAD^{commit}')"
base=''

if [[ -n "${CMS_CI_BASE_REF:-}" ]]; then
    if ! base_commit="$(source_repository rev-parse --verify --quiet --end-of-options "${CMS_CI_BASE_REF}^{commit}")"; then
        echo "ci-entry: CMS_CI_BASE_REF=${CMS_CI_BASE_REF} names no commit in the mounted repository." >&2
        exit 1
    fi

    if ! base="$(source_repository merge-base "$base_commit" "$commit")"; then
        echo "ci-entry: CMS_CI_BASE_REF=${CMS_CI_BASE_REF} has no merge base with HEAD ${commit}." >&2
        exit 1
    fi
fi

rm -rf "$work"
mkdir -p "$work"
cd "$work"
git -c init.defaultBranch=main init --quiet

# Replaces the files of the new repository with the tree of a commit of the mounted one, and
# commits them. Every file came from the archive, so every file was tracked: add ignored ones too.
commit_tree() {
    find . -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
    source_repository archive --format=tar "$1" | tar -x -C "$work"
    git add --all --force
    git -c user.name='bin/ci' -c user.email='ci@localhost.invalid' -c commit.gpgsign=false -c core.hooksPath=/dev/null \
        commit --quiet --allow-empty --message="$2"
}

if [[ -n "$base" ]]; then
    commit_tree "$base" "the merge base ${base} of CMS_CI_BASE_REF=${CMS_CI_BASE_REF} and HEAD"
fi

commit_tree "$commit" "HEAD ${commit} of the mounted repository"

if [[ -n "$base" ]]; then
    export CMS_CI_BASE_REF=HEAD~1
    echo "ci-entry: HEAD ${commit} archived to ${work} on its merge base ${base}; CMS_CI_BASE_REF=HEAD~1"
else
    unset CMS_CI_BASE_REF
    echo "ci-entry: HEAD ${commit} archived to ${work}"
fi

if [[ $# -eq 0 ]]; then
    docker/ci-setup.sh
    set -- bin/ci
fi

exec "$@"
