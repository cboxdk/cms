---
title: Serve the workbench and log in
weight: 12
description: Start the services, prepare the dev database, create the first member of staff, give them access, serve the workbench and log in to the panel at http://127.0.0.1:8080/cms.
---

# Serve the workbench and log in

This page takes a checkout from nothing to a member of staff logged in to the panel of the workbench. Do it in the main checkout, never in a git worktree: the dev database `cms` is shared by every checkout, and `composer dev:prepare` runs the main checkout's migrations on it. It needs Docker and the dependencies of [Installation](installation.md#the-dependencies): `composer install` and `npm ci`.

Every command below that runs the workbench's Artisan runs it in the php-baseimages dev image, the image the gates run in, through `composer image:run`, so it reaches Postgres and Valkey by their names on the network of the services, as the served workbench does.

## 1. Start the services

`composer services:up` starts Postgres 18, Valkey 8 and the php container from `compose.yaml` and waits until they are healthy. [Installation](installation.md#the-services) explains them. The commands below never start the services; when they are down, each says so and names this command.

## 2. Prepare the workbench and the dev database

`composer dev:prepare` runs these steps in order, each in the dev image, and stops at the first that fails, with its exit code:

1. `tools/bin/workbench-env.php` gives `workbench/.env` an `APP_KEY`, made from `workbench/.env.example` when the file is missing. Laravel refuses every request of the panel without a key. A file with a key is never changed, so a session survives a second run.
2. The migrations, as the owner role.
3. `cms:partitions:maintain`, the partitions from now to 14 days ahead.
4. `cms:build`, the registry cache.
5. `cms:install`, the installation operator, the service actor the maintenance commands run as.
6. `cms:sites:sync`, which registers the workbench's site, `workbench`, in Danish and English at `http://127.0.0.1:8080`, with its root node.
7. `composer panel:build`, the panel's scripts and styles.

Every step is idempotent: run it again after pulling new code, after `composer install`. The line of `cms:sites:sync` names the site's root node, the first time as `registered workbench: site <id>, root node <id>, ...` and afterwards as `unchanged workbench: site <id>, root node <id>, ...`. Keep the root node's id for step 4.

## 3. Create the first member of staff

`composer image:run -- php vendor/bin/testbench cms:staff:create --email=ada@example.com --name="Ada Lovelace"` registers a local member of staff with your email and name. It asks for the password twice, hidden, and prints the new actor's id alone on its line; keep it for step 4.

The password must have at least 12 characters and must not be known from data breaches. The breach check sends the first 5 characters of the password's SHA-1 to the range API of Have I Been Pwned, so it needs a network; without one the command exits 75 with `breached_passwords_unavailable` and sets nothing, and you run it again. [Local accounts](../security/local-accounts.md#creating-a-member-of-staff) lists every exit code.

## 4. Give the member of staff access

A new member of staff holds no grant, and nobody can give the first grant from the panel, because a grant gives only what its giver holds. `composer image:run -- php vendor/bin/testbench cms:access:bootstrap <actor id> <root node id>` gives it once: the role `administrator`, with every command and query, on the workbench site's root node, so on the whole site. Once any member of staff holds a grant it is refused with `access_bootstrap_done`; give further access in the panel. [The access bootstrap](../developers/maintenance-commands.md#the-access-bootstrap) lists its refusals.

## 5. Serve the workbench

`composer workbench:serve` serves the workbench in a container of the dev image, with Laravel's development server on port 8080 published on your machine's `127.0.0.1` only. It says where the panel is, and Ctrl-C stops it. When port 8080 is taken, `composer workbench:serve -- --port=9000` publishes another; the site's links then still name 8080.

Before it starts, it checks what the panel needs, and stops with the fix when something is missing: an `APP_KEY` in `workbench/.env`, the same file in Testbench's application, which reads only its own copy, and the panel's build, all of which `composer dev:prepare` makes.

## 6. Log in

Open `http://127.0.0.1:8080/cms`. The panel sends you to the login page; log in with the email and the password of step 3.

![The login page of the workbench on a desktop: the email and password fields, the link for a forgotten password, and the notices above the form, among them why the panel sent you here.](../screenshots/login.png)

You land on the start page. The navigation lists the pages the bootstrap role lets you open, and Ctrl+K, or Command+K on a Mac, opens the command palette with every page and command you may use.

![The start page after the first login: the navigation the bootstrap role gives, the search that opens the command palette, and the button that signs out.](../screenshots/home.png)

The workbench installs a fixture addon, which the tests use, and its entries, such as the notice on the login page and the article entry in the navigation, show their translation keys: the panel does not load an addon's texts yet ([An addon's texts](../ui/i18n.md#an-addons-texts)).

[Using the panel](../users/_index.md) says what a person who logs in can do. To give a colleague access, create them with step 3 and give them a role on the grants page; [Roles and grants](../addons/panel/pages.md#roles-and-grants) describes the pages.

## When something goes wrong

- A command says the shared services are not running: run `composer services:up` in the main checkout.
- `composer workbench:serve` stops because a piece is missing: run `composer dev:prepare`, which makes each of them.
- You changed `workbench/.env`: run `composer dev:prepare` again, which copies it to Testbench's application.
- The panel shows an error after you pulled new code: run `composer install` and `composer dev:prepare`, then start `composer workbench:serve` again.
- You forgot the password: the workbench sends no mail, so print a reset link with `composer image:run -- php vendor/bin/testbench cms:staff:reset-link ada@example.com` and open it while the workbench is served. [Signing in](../users/signing-in.md#a-forgotten-password) shows the pages.

## What the tests hold

`tests/Postgres/GettingStartedPathTest.php` runs the scripted part of this path on the checkout's test database: the Artisan steps of `composer dev:prepare` twice, the second changing nothing, `cms:staff:create`, `cms:access:bootstrap` with the root node `cms:sites:sync` printed, a login through the panel's form and a visit of the who-am-I page. `tests/Browser/Panel/StaffJourneyTest.php` follows it in Chromium: the first member of staff logs in, opens the command palette with the keyboard, gives a second member of staff a role that is not administrative, sees the grant and logs out. Following this page in a browser on the main checkout stays a manual check.
