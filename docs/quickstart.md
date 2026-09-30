---
title: Quickstart
weight: 2
description: From a clone of the repository to running services, a prepared dev database, a green composer check and a clean cms:doctor.
---

# Quickstart

This is the contributor's path from a clone to a working checkout. It needs Git, Docker, PHP 8.5 with Composer, and Node 22.13 or newer on the host; [Requirements](requirements.md) has the details, and [Installation](getting-started/installation.md) explains each step.

1. Install the dependencies: `composer install`, then `npm ci`. Composer's `post-autoload-dump` script runs `cms:build`, so the registry cache is there from the start.
2. Download the browser for the browser tests on the host once per machine: `npx playwright install chromium`. The gates and `composer image:run` use the Chromium of the dev image, so they do not need it.
3. Start the services: `composer services:up`. It starts PHP 8.5, Postgres 18 and Valkey 8 in Docker, waits until they are healthy and sets up the roles and databases.
4. Prepare the dev database: `composer dev:prepare`. It runs the migrations as the owner role, creates the partitions ahead of the clock and builds the registry cache. Run it from the main checkout.
5. Run the gates: `composer check`. It runs gates 1 to 6 and exits 1 when one fails.
6. Check the installation: `docker compose exec php vendor/bin/testbench cms:doctor`, from the main checkout.

`composer check` prints each gate with each of its steps, and a summary at the end. Gates 7 to 11 belong to the PR profile that CI runs, so the local run lists them as not run.

`cms:doctor` lists every check with what it found:

![cms:doctor on a healthy installation. Every runtime check passes, and each line says what the check looked at and what it found.](screenshots/doctor.svg)

`--dev` adds the checks of Node, Playwright and Chromium. Run it where the browser tests run, on the host: `php -d allow_url_fopen=0 vendor/bin/testbench cms:doctor --dev`.

## Next

- [Testing with the testkit](getting-started/testing.md): the fakes and the suites.
- [Gates and CI](developers/gates-and-ci.md): what each gate checks and how CI runs them.
- [Addons](addons/_index.md): the extension points and their running examples.
