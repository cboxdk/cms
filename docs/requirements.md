---
title: Requirements
weight: 3
description: The PHP, Laravel and package versions Composer enforces, the services cms:doctor checks at run time, and the tools the development environment needs.
---

# Requirements

## Enforced by Composer

The kernel packages require these, as their `composer.json` files state them. The packages of the kernel itself, `cboxdk/cms-*`, are left out. A test holds this table to the `composer.json` files.

| Package | Constraint | Required by |
|---|---|---|
| `composer-runtime-api` | `^2.2` | `cboxdk/cms-generators` |
| `illuminate/console` | `^13.0` | `cboxdk/cms-cli`, `cboxdk/cms-core`, `cboxdk/cms-generators` |
| `illuminate/contracts` | `^13.0` | `cboxdk/cms-generators` |
| `illuminate/database` | `^13.0` | `cboxdk/cms-core` |
| `illuminate/redis` | `^13.0` | `cboxdk/cms-core` |
| `illuminate/support` | `^13.0` | `cboxdk/cms-cli`, `cboxdk/cms-core`, `cboxdk/cms-generators`, `cboxdk/cms-http` |
| `opis/json-schema` | `^2.6` | `cboxdk/cms-generators` |
| `php` | `^8.5` | `cboxdk/cms-cli`, `cboxdk/cms-contracts`, `cboxdk/cms-core`, `cboxdk/cms-generators`, `cboxdk/cms-http` |
| `psr/clock` | `^1.0` | `cboxdk/cms-core` |
| `psr/log` | `^3.0` | `cboxdk/cms-cli` |
| `symfony/process` | `^7.4.5 \|\| ^8.0.5` | `cboxdk/cms-core` |
| `symfony/yaml` | `^8.0` | `cboxdk/cms-generators` |

An addon's tests use the testkit, `cboxdk/cms-testkit`, as a development dependency. It brings the test and analysis tools the kernel uses:

| Package | Constraint |
|---|---|
| `driftingly/rector-laravel` | `^2.6` |
| `larastan/larastan` | `^3.12` |
| `laravel/framework` | `^13.0` |
| `laravel/pint` | `^1.32` |
| `laravel/serializable-closure` | `^2.0.10` |
| `orchestra/testbench` | `^11.0` |
| `php` | `^8.5` |
| `phpstan/phpstan` | `^2.2` |
| `phpunit/phpunit` | `^13.3.4` |
| `psr/clock` | `^1.0` |
| `rector/rector` | `^2.6` |
| `symfony/process` | `^7.4.5 \|\| ^8.0.5` |

## Checked by cms:doctor

Composer cannot check the services and the PHP settings. [cms:doctor](developers/doctor.md) does, when the application runs:

- PHP 8.5 or newer, with `allow_url_fopen` off.
- Laravel 13.
- Postgres 17 or newer, reachable as the app role, with the role settings of the [operating contract](security/postgres-roles.md), such as `transaction_timeout` above zero, `max_prepared_transactions` 0 and English messages.
- Valkey, answering PING on the configured Redis connection.
- The PHP extensions the connections use: `pdo_pgsql` for Postgres and, with Laravel's default Redis client, `redis`. `postgres.reachable` and `valkey.reachable` fail without them. The `composer.json` files do not list them.

## For development

- Docker, for the services of `compose.yaml`: `ghcr.io/cboxdk/php-baseimages/php-cli:8.5-bookworm-dev-v1`, `ghcr.io/cboxdk/postgres:18` and `ghcr.io/cboxdk/valkey:8`. The tests run on Postgres 18, and the code uses nothing that Postgres 17 lacks.
- Node `^22.13.0 || >=24`, as `package.json` states, for the JS gates and Playwright. `cms:doctor --dev` checks Node, Playwright and Chromium.
- Chromium for Playwright, downloaded once per machine with `npx playwright install chromium`.
