# laravel-cms

Monorepo for Cbox CMS: the kernel, packages, sidecars and JS packages. Nothing is pushed anywhere; there is no remote yet.

## Sources of truth

These live in the planning repo and are read-only unless a rule below says otherwise:

- `/Users/sylvester/Projects/cbox-cms/PRD.md`: what is built (Danish).
- `/Users/sylvester/Projects/cbox-cms/GUARDRAILS.md`: how code is written. Every rule applies here.
- `/Users/sylvester/Projects/cbox-cms/MILESTONES.md`: the build order and exit criteria.
- `PROGRESS.md` in this repo: the working state. Read it first in every session and every agent task that changes code.

## Hard rules

- Never `git push`, never add a remote, never publish packages. Commit locally only.
- One commit per task, message `<block>-<task>: <what>`, for example `M1-T3: command envelope and idempotency store`.
- Follow GUARDRAILS 7.3: never weaken a check that verifies your own change (analysis config, test filters, snapshots, architecture tests, CI). If you believe a check is wrong, leave it, and add the case under "Til review af Sylvester" in `PROGRESS.md`.
- A bug fix has a regression test that fails before the fix.
- PHP 8.5, Laravel 13 only, PHPStan level 10 without baseline, Pest 4, Rector, Pint, React 19 with strict TypeScript.
- Run the checks before saying a task is done. Until `composer check` exists (milestone 0 creates it), run the individual tools that exist.
- Services for tests run in Docker on cboxdk images: `ghcr.io/cboxdk/postgres:18` and `ghcr.io/cboxdk/valkey:8`; PHP on `ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1`. Do not use Herd's Postgres for tests. Postgres 17 stays the minimum, so never use features that arrived in 18 (GUARDRAILS 1.2).

## When the PRD is unclear

- If the PRD is ambiguous but one reading is clearly consistent with the rest of the PRD and GUARDRAILS, pick it, implement it, and record the interpretation under "Tolkninger" in `PROGRESS.md`.
- If the PRD needs a small correction to be buildable, edit `PRD.md` in the planning repo, add a version entry at the top of its section 0, and commit it there with message `PRD v2.x: ...`.
- If it is a decision reserved for Sylvester (the open questions in PRD section 26, product scope, naming, licensing, anything outward-facing), do not decide. Add it under "Blokeret" in `PROGRESS.md` with what it blocks, and continue with work that does not depend on it.

## Autopilot

`.harness/autopilot.on` switches on the stop guard in `.harness/stop-hook.sh`. While it is on, a session may not stop until `PROGRESS.md` says `STATUS: complete` or `STATUS: blocked`. `.harness/waiting` marks that a background workflow is running and lets the session idle until it reports back. The workflow for one block is `.claude/workflows/cms-milestone.js`.

Delete `.harness/autopilot.on` to stop autopilot.
