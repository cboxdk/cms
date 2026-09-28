# Cbox CMS

A CMS for Laravel, built on PHP 8.5, Laravel 13, Postgres and Valkey.

Cbox CMS is in development and has no release yet. This repository holds the kernel packages, the workbench application they run in, and the tools that check every change. What exists today is described in the [documentation](docs/index.md).

## Documentation

- [Overview](docs/index.md): the mental model and what exists today.
- [Quickstart](docs/quickstart.md): from a clone to a green `composer check`.
- [Requirements](docs/requirements.md): the versions and services.
- [Getting started](docs/getting-started/_index.md), [Developers](docs/developers/_index.md), [Addons](docs/addons/_index.md) and [Security](docs/security/_index.md).

## Development

With Docker, PHP 8.5 and Node 22.13 or newer:

1. `composer install` and `npm ci`
2. `composer services:up`
3. `composer dev:prepare`
4. `composer check`

[Installation](docs/getting-started/installation.md) explains each step, and [Gates and CI](docs/developers/gates-and-ci.md) what `composer check` runs.
