# Cbox CMS

A CMS for Laravel, built on PHP 8.5, Laravel 13, Postgres and Valkey.

## Status

Cbox CMS is pre-release. There is no tagged version and no package on Packagist, and nothing here is ready for production content yet.

- **Milestone 0 is done:** the toolchain and the kernel's foundations. That is the contracts for the clock, ids, receipts and idempotency with their Postgres stores, the partition manager, the blueprint schema v1 with `cms:generate`, the compiled registry of commands and hooks, `cms:doctor`, and the gates every change passes.
- **Milestone 1 is done:** the walking skeleton. It brought the command pipeline, the event log, delivery of content by path, and the REST, CLI and MCP surfaces, proven with content types that exist only as test fixtures.
- **The panel's first part is there:** local login for staff with sessions, the panel's shell with the command palette, and roles and grants, which you can [log in to on the workbench](docs/getting-started/first-login.md).
- **Not there yet:** editing content in the panel, and everything else an editor would use.

[What exists today](docs/index.md#what-exists-today) lists it in more detail.

## What it will be

Cbox CMS is meant for large editorial installations: millions of entries, several brands in one installation, and stories shared between sites without copying them. Every write is a command that commits one changeset in Postgres, so the state lives in the database and not on a node. The kernel knows no content types; every type and field comes from blueprint files, and the typed code and clients are generated from them.

## Requirements

PHP 8.5 and Laravel 13, Postgres 17 or newer and Valkey. Development also needs Docker and Node 22.13 or newer. [Requirements](docs/requirements.md) has the full list.

## Contributing

With Docker, PHP 8.5 and Node on the host:

1. `composer install` and `npm ci`
2. `npx playwright install chromium`, once per machine
3. `composer services:up`
4. `composer dev:prepare`
5. `composer check`
6. `composer workbench:serve`, then log in at `http://127.0.0.1:8080/cms` with the member of staff that [Serve the workbench and log in](docs/getting-started/first-login.md) creates

`cms:doctor` then checks the installation:

![cms:doctor on a healthy installation. Every runtime check passes, and each line says what the check looked at and what it found.](docs/screenshots/doctor.svg)

[Quickstart](docs/quickstart.md) explains each step, and [CONTRIBUTING.md](CONTRIBUTING.md) the gates, the commit format and the rules for changing a check.

## Documentation

- [Overview](docs/index.md): the mental model and what exists today.
- [Quickstart](docs/quickstart.md): from a clone to a green `composer check`.
- [Requirements](docs/requirements.md): the versions and services.
- [Getting started](docs/getting-started/_index.md), [Using the panel](docs/users/_index.md), [Developers](docs/developers/_index.md), [Addons](docs/addons/_index.md), [Recipes](docs/recipes/_index.md) and [Security](docs/security/_index.md).

## Security

Report a vulnerability privately, as [SECURITY.md](SECURITY.md) describes. Do not open a public issue for it.

## License

Cbox CMS is open source under the [MIT license](LICENSE).
