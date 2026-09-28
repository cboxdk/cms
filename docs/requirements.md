---
title: Requirements
weight: 3
description: The PHP, Laravel and package versions Composer enforces, the services cms:doctor checks at run time, and the tools the development environment needs.
---

# Requirements

## Enforced by Composer

Cbox CMS is one package, `cboxdk/cms`, with the kernel and the first-party modules as namespaces. It requires these, as its `composer.json` states them. A test holds both tables to `composer.json`.

| Package | Constraint |
|---|---|
| `composer-runtime-api` | `^2.2` |
| `illuminate/console` | `^13.0` |
| `illuminate/contracts` | `^13.0` |
| `illuminate/database` | `^13.0` |
| `illuminate/redis` | `^13.0` |
| `illuminate/support` | `^13.0` |
| `php` | `^8.5` |
| `psr/clock` | `^1.0` |
| `psr/log` | `^3.0` |
| `symfony/console` | `^7.4 \|\| ^8.0` |
| `symfony/process` | `^7.4.5 \|\| ^8.0.5` |

It suggests these and does not require them, so they never reach production. The testkit and the generators need them in development, and an application or addon installs them with `composer require --dev`:

| Package | Needed for |
|---|---|
| `driftingly/rector-laravel` | Required by the testkit's shared Rector configuration (packages/testkit/config/rector.php); install it with require-dev. |
| `larastan/larastan` | Required by the testkit's shared PHPStan configuration (packages/testkit/config/phpstan.neon); install it with require-dev. |
| `laravel/pint` | Required by the testkit's shared Pint configuration (packages/testkit/config/pint.json); install it with require-dev. |
| `opis/json-schema` | Required by cms:generate, which validates blueprints against blueprint.v1.json; install it with require-dev. |
| `orchestra/testbench` | Required by the testkit's harnesses and fakes for tests; install it with require-dev. |
| `phpstan/phpstan` | Required by the testkit's PHPStan rules and shared configuration; install it with require-dev. |
| `phpunit/phpunit` | Required by the testkit's shared contract suites; install it with require-dev. |
| `rector/rector` | Required by the testkit's shared Rector configuration; install it with require-dev. |
| `symfony/yaml` | Required by cms:generate, which reads the blueprint files; install it with require-dev. |

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
