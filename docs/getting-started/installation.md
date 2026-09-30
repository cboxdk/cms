---
title: Installation
weight: 11
description: Install the development environment of the repository, start the shared services, prepare the dev database, and run the commands in the workbench.
---

# Installation

The development environment is this repository, the services of `compose.yaml` in Docker, and the workbench: a Laravel application from Orchestra Testbench that loads the package `cboxdk/cms`, this repository, with its modules in `packages/`, and runs their commands.

## The dependencies

Run `composer install` and `npm ci` in the checkout. The Composer packages come from `composer.lock` and the npm packages from `package-lock.json`; `cboxdk/cms` is the root package, so Composer autoloads its modules from `packages/<module>/src` and installs nothing of its own into `vendor/`. The workbench registers the providers of `cboxdk/cms` from `testbench.yaml`, because Testbench discovers a root package's providers only in its command line. After the install, Composer's `post-autoload-dump` script discovers the installed packages' service providers and runs `cms:build`, so the registry cache is current after every `composer install`, `composer update` and `composer dump-autoload`.

The browser tests need Chromium in the version Playwright pins. Download it once per machine, and again after the Playwright version in `package.json` changes: `npx playwright install chromium`.

## The services

`composer services:up` starts three containers from `compose.yaml` and waits until they are healthy:

| Service | Image | Port on the host |
|---|---|---|
| `php` | `ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1` | none |
| `postgres` | `ghcr.io/cboxdk/postgres:18` | `127.0.0.1:54317` |
| `valkey` | `ghcr.io/cboxdk/valkey:8` | `127.0.0.1:63797` |

It then runs the Postgres init script again, which is idempotent, so an existing data volume gets the current roles and databases. The ports bind to `127.0.0.1` only. `composer services:down` stops the containers and keeps the data volumes.

The php container runs as your user, mounts the main checkout at `/var/www/html`, never a worktree, and has the PHP settings of the runtime contract, among them `allow_url_fopen = Off`. Commands that must see the runtime contract, such as `cms:doctor`, run there: `docker compose exec php <command>` in the main checkout. The gates run in a container of the same image of their own, for whichever checkout runs them; see [Gates and CI](../developers/gates-and-ci.md#the-dev-image).

The services are shared by every checkout of the repository on the machine, git worktrees included. [Services and isolation](../developers/services.md) explains how the tests of two checkouts stay apart.

## The dev database

The workbench's database is `cms` on the shared Postgres. `composer dev:prepare` readies it, from the main checkout after `composer services:up`:

1. the migrations, as the owner role on the connection `pgsql_owner`;
2. `cms:partitions:maintain`, which creates the partitions from now to 14 days ahead;
3. `cms:build`, the registry cache.

Every step is idempotent, and the script stops at the first step that fails. The workbench reads its settings from `workbench/.env`; Testbench copies `workbench/.env.example` there when the file is missing.

## Running a command in the workbench

`vendor/bin/testbench` is the workbench's Artisan. `vendor/bin/testbench list cms` lists the kernel's commands:

| Command | What it does |
|---|---|
| `cms:build` | Compiles the registries of actions, commands, hooks, schema contributions and subscribers to `bootstrap/cache/cms`, from the scan roots and addon manifests. See [Build declarations](../addons/build-declarations.md). |
| `cms:doctor` | Checks the installation and the runtime contract. See [cms:doctor](../developers/doctor.md). |
| `cms:generate` | Generates the typed PHP and TypeScript code and the migrations of the type tables from the blueprint files. See [Blueprint schema v1](../addons/blueprint-v1.md). |
| `cms:partitions:maintain` | Creates partitions ahead of the clock and removes partitions past retention, as the owner role. See [Partitions](../developers/partitions.md). |
| `cms:schema:editor` | Writes the line that points editors at the blueprint schema into every blueprint file. |

On the host, PHP usually has `allow_url_fopen` on, and `cms:doctor` then fails `php.allow_url_fopen`. Run it in the php container, or on the host as `php -d allow_url_fopen=0 vendor/bin/testbench cms:doctor`.

## The first run of the gates

`composer check` runs gates 1 to 6 with the services up, in the dev image. The first run in a checkout also installs its Linux `node_modules` in a Docker volume, and it creates the checkout's own test database the first time the Postgres suite runs. [Gates and CI](../developers/gates-and-ci.md) describes each gate.
