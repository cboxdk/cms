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
# request's base commit is in ci.yml; bin/ci reads it only with CMS_CI_MUTATION=1, because mutation
# testing is deferred until after v1 (Sylvester, 2 October 2026). The script resolves it in the mounted repository and builds
# the new repository as two commits: the tree of the merge base of CMS_CI_BASE_REF and HEAD, then
# the tree of HEAD. Inside, CMS_CI_BASE_REF is HEAD~1, so the step mutates what changed since the
# merge base. A ref that names no commit, or has no merge base with HEAD, stops the script before
# anything runs.
#
# Unset, empty or 40 zeros, the base is derived in the mounted repository as bin/ci derives it
# (tools/src/Mutation/Boundary/GitMutationScope.php): HEAD~1 when HEAD is the branch main, the
# merge base of HEAD and origin/main on another branch or a detached HEAD (main when there is no
# origin/main, as in a repository without a remote), and the two commits are built from it. When
# HEAD is the only commit of the repository or the first commit of main, the new repository has one
# commit, CMS_CI_BASE_REF stays unset, and bin/ci counts every file as changed. A HEAD whose base
# cannot be derived stops the script before anything runs.
set -euo pipefail

source_git="${CMS_CI_SOURCE:-/source.git}"
work="${CMS_CI_WORK:-/work}"

source_repository() {
    git -c safe.directory='*' --git-dir="$source_git" "$@"
}

commit="$(source_repository rev-parse --verify 'HEAD^{commit}')"
base=''
# How the base was found, in the log line and in the message of the base's commit.
base_note=''
base_message=''

if [[ -z "${CMS_CI_BASE_REF:-}" || -z "${CMS_CI_BASE_REF//[[:space:]]/}" ]]; then
    missing="CMS_CI_BASE_REF is $([[ -z "${CMS_CI_BASE_REF+set}" ]] && echo 'not set' || echo 'empty')"
elif [[ "$CMS_CI_BASE_REF" =~ ^[[:space:]]*0{40}[[:space:]]*$ ]]; then
    missing='CMS_CI_BASE_REF is 40 zeros, the commit before a push that created the branch'
else
    missing=''
fi

if [[ -n "$missing" ]]; then
    on_main=false
    only_commit=false
    [[ "$(source_repository symbolic-ref --quiet HEAD || true)" == refs/heads/main ]] && on_main=true
    [[ "$(source_repository rev-list --count --all)" == 1 ]] && only_commit=true

    if parent="$(source_repository rev-parse --verify --quiet 'HEAD~1^{commit}')"; then
        has_parent=true
    else
        has_parent=false
    fi

    if [[ $has_parent == false ]] && { [[ $on_main == true ]] || [[ $only_commit == true ]]; }; then
        # One commit in the new repository: bin/ci derives the empty tree there and counts every
        # file as changed.
        base=''
    elif [[ $on_main == true ]]; then
        base="$parent"
        base_note="HEAD~1 of main, as ${missing} and HEAD is main"
    else
        for mainline in origin/main main; do
            if mainline_commit="$(source_repository rev-parse --verify --quiet --end-of-options "${mainline}^{commit}")"; then
                if ! base="$(source_repository merge-base "$mainline_commit" "$commit")"; then
                    echo "ci-entry: ${missing}, and ${mainline} has no merge base with HEAD ${commit}." >&2
                    exit 1
                fi

                base_note="the merge base of ${mainline} and HEAD, as ${missing} and HEAD is not main"
                break
            fi
        done

        if [[ -z "$base" ]]; then
            echo "ci-entry: ${missing}, HEAD is not main, and neither origin/main nor main names a commit in the mounted repository." >&2
            exit 1
        fi
    fi
else
    if ! base_commit="$(source_repository rev-parse --verify --quiet --end-of-options "${CMS_CI_BASE_REF}^{commit}")"; then
        echo "ci-entry: CMS_CI_BASE_REF=${CMS_CI_BASE_REF} names no commit in the mounted repository." >&2
        exit 1
    fi

    if ! base="$(source_repository merge-base "$base_commit" "$commit")"; then
        echo "ci-entry: CMS_CI_BASE_REF=${CMS_CI_BASE_REF} has no merge base with HEAD ${commit}." >&2
        exit 1
    fi

fi

if [[ -n "$missing" && -n "$base" ]]; then
    base_message="the base ${base}, ${base_note}"
    base_note="base ${base}, ${base_note}"
elif [[ -n "$base" ]]; then
    base_message="the merge base ${base} of CMS_CI_BASE_REF=${CMS_CI_BASE_REF} and HEAD"
    base_note="merge base ${base}"
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
    commit_tree "$base" "$base_message"
fi

commit_tree "$commit" "HEAD ${commit} of the mounted repository"

if [[ -n "$base" ]]; then
    export CMS_CI_BASE_REF=HEAD~1
    echo "ci-entry: HEAD ${commit} archived to ${work} on its ${base_note}; CMS_CI_BASE_REF=HEAD~1"
else
    unset CMS_CI_BASE_REF
    echo "ci-entry: HEAD ${commit} archived to ${work}"
fi

if [[ $# -eq 0 ]]; then
    docker/ci-setup.sh
    set -- bin/ci
fi

exec "$@"
