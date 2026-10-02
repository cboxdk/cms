# Contributing

Cbox CMS is pre-release, and the rules below are the rules every change in this repository follows, also the maintainers' own.

## Setting up

[Quickstart](docs/quickstart.md) takes you from a clone to a green `composer check`, and [Installation](docs/getting-started/installation.md) explains each step. You need Docker, PHP 8.5 with Composer and Node 22.13 or newer, as [Requirements](docs/requirements.md) lists.

## One package with modules

The repository is one Composer package, `cboxdk/cms`. The kernel is a set of modules, each a namespace below `Cbox\Cms` with its code in `packages/<module>/src` and its tests in `packages/<module>/tests`: `contracts`, `core`, `generators`, `cli`, `http` and `testkit`. The modules have no `composer.json` of their own; the Arch suite keeps them apart, and inside a module the layers `Domain`, `Actions`, `Boundary`, `Adapter` and `Infrastructure` decide what code may use. [Architecture and layers](docs/developers/architecture.md) describes both.

## The gates

Every change passes the gates. Run them before you ask for review:

- `composer check` runs gates 1 to 6: formatting, Rector, PHPStan at level 10, tsc and ESLint, the Pest suites, and a check that generated code matches the committed code. It needs the services from `composer services:up` and exits 1 when a gate fails.
- CI runs `bin/ci` on every pull request, which adds mutation testing on the changed files, split into parallel shards, the browser tests, the dependency audit and `composer docs:check`.

[Gates and CI](docs/developers/gates-and-ci.md) describes each gate and how to run CI in a container on your machine.

A few rules the gates cannot check on their own:

- A bug fix comes with a regression test that fails before the fix.
- Nothing committed is unfinished: no marker comments for later work, no skipped tests, no stub bodies.
- A new public extension point comes with a page in `docs/` and a running example that a suite tests.
- A task that comes up again and again comes with a recipe in `docs/recipes/`, written in the shape the other recipes have.

## Commits

One commit per task, with the message `<block>-<task>: <what>`, for example `M1-T3: command envelope and idempotency store`. The block and task ids are those of the build plan in `PROGRESS.md`. A review fix for a block uses `<block>-review: <what>`.

## Changing a check

Never weaken a check to make your change pass: an analysis setting, a test filter, an expectation, an architecture test or a CI step. If you think a check is wrong, leave it and say so in the pull request.

When a change adds, changes or removes a check or a test, record it in `CHECKS-LOG.md`, under the heading of its block, in an entry that starts with the task id, says GUARDRAILS 7.3, and gives what changed and why. `composer progress:check -- <block>-<task> --changed-checks` fails when the entry is missing.

## Security

Report a vulnerability privately, as [SECURITY.md](SECURITY.md) describes, not in an issue.

## License

By contributing, you agree that your contribution is licensed under the [MIT license](LICENSE) of the repository.
