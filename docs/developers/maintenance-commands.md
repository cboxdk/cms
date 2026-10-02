---
title: Maintenance commands
weight: 30
description: How cms:install creates the installation operator once, why its genesis is the one exception to invariant 37, and how the maintenance commands run as the operator through a pipeline that allows only a named list of commands.
---

# Maintenance commands

A fresh installation has no actor that holds a grant, so no command through the normal pipeline can run: the kernel's authorizer allows a command only through a role of the actor (PRD 5.10). The maintenance commands are the console work that sets an installation up, such as the first site, the roles and the first staff member. They run as the installation operator, a service actor that `cms:install` creates once, through a pipeline of their own that allows only a named list of commands. Everything else about them is the kernel's: the command transaction, the phases, idempotency, the commit and the audit.

## cms:install

`php artisan cms:install` runs in the maintenance process, the console process that has the owner connection (`cbox-cms.database.owner_connection`) and runs the migrations and `cms:partitions:maintain`. Run it after the migrations and `cms:partitions:maintain`, because the genesis writes into today's partitions. In the workbench, `composer dev:prepare` runs it as its last step.

The first run writes the genesis in one transaction on the owner connection:

- the operator, a service actor that is registered and activated at once: state active, version 2, credential generation 1, and no person responsible for it;
- the one row of the kernel table `installation`, which names the operator, the genesis changeset and its time;
- the genesis changeset, `installation.genesis` version 1, in which the operator is its own actor, from the internal issuer `maintenance` with the issuer kind `system`, keyed by the unit of work `install:operator`, with its audit row on the actor;
- the events `actor.registered` and `actor.activated`, as `actor.register` and `actor.activate` would have written them.

The table and the actor are written by the owner function `cms_install_operator()`, which only the owner role may run. It locks `installation` and refuses once the table has a row, so two installs run one after the other and the second changes nothing. The id is kept in the database, never in `.env` or the configuration, so every process and every deploy finds the same operator, and the app role reads it through the lookup `cms_installation_operator()`.

A second run writes nothing, prints the operator's id and exits 0. The exit codes come from the error catalog:

| Exit | Code | When |
|---|---|---|
| 0 | | the operator was created, or the installation has one |
| 78 | [`owner_credentials_exposed`](../reference/errors.md#owner_credentials_exposed) | the process serves HTTP or runs queued jobs; `cms:install` runs only in a console process |
| 78 | [`install_owner_connection_required`](../reference/errors.md#install_owner_connection_required) | the process has no owner connection, or the connection named is not the owner role's |
| 75 | [`partition_missing`](../reference/errors.md#partition_missing) | no partition covers the genesis; run `cms:partitions:maintain` first |

`cms:doctor` checks the result with `identity.operator_actor`, which does not block: the operator exists, is of class service and is active. Before `cms:install` it fails with [`doctor_operator_missing`](../reference/errors.md#doctor_operator_missing) and the doctor exits 79, not ready.

## The genesis and invariant 37

Invariant 37 says that a command's actor, and every actor it acts on behalf of, is active before the command runs, and the pipeline refuses any other with `actor_not_active`. The genesis is the one exception: the operator is the actor of the changeset that creates it. It is not a command through the pipeline, it runs only as the owner role and only while `installation` is empty, so it can happen once per installation. Every other actor is created pending by `actor.register` and becomes active only by `actor.activate`, and a command whose actor is pending is still refused.

The operator has no responsible person, unlike every service actor that `actor.register` creates, because no person exists in the installation when it is made. Both points are recorded for review in `PROGRESS.md`.

## Running a maintenance command

The core action `RunMaintenanceCommand` runs one command as the operator. It takes the command and a unit of work, and:

1. reads the operator from `installation`; without one, the call is rejected with [`installation_operator_missing`](../reference/errors.md#installation_operator_missing) and nothing runs;
2. builds the envelope of the internal issuer `maintenance`, issuer kind `system`, with the operator as its actor and acting on behalf of no one, and the idempotency key derived from the unit of work;
3. gets the operator's access context from its grants, as for any caller;
4. runs the command through the maintenance pipeline.

The unit of work names the work, not the run, so a rerun of the same work replays the first run's receipt. Once the idempotency record has expired, after seven days, a rerun plans nothing for an aggregate that already exists. Use a unit that the same input always gives, such as `sites:<handle>:<hash of the configured locales>` for a site or `staff:<sha256 of the lowercased email>` for a staff member.

The maintenance pipeline is the kernel's command pipeline with one difference, its authorizer. `MaintenanceAuthorizer` allows a command only when all of these hold, and refuses every other call as [`unauthorized`](../reference/errors.md#unauthorized):

- the command is on its list, `MaintenanceAuthorizer::COMMANDS`;
- the envelope comes from the internal issuer `maintenance`;
- the principal is the installation operator, acting on behalf of no one, and the envelope's actor is the operator too.

The operator holds no grant, so the kernel's own authorizer refuses it every command, and the maintenance authorizer runs only in this pipeline, which `CoreServiceProvider` gives `RunMaintenanceCommand` alone.

## The list of commands

| Command | Added by | What it sets up |
|---|---|---|
| `actor.register` | block B1, task 4 | a staff or service actor, pending |
| `actor.activate` | block B1, task 4 | a pending actor, active |
| `site.register` | block B1, task 6 | a site with its root node and locales, from `cbox-cms.sites`, through `cms:sites:sync` ([site commands](../addons/site-commands.md)) |
| `role.create` | block B1, task 8 | a role with its permissions |
| `grant.assign` | block B1, task 8 | a grant of a role on a node |

The list names all five, also the commands a later task of block B1 builds; a name the registry does not know never reaches the authorizer, because the pipeline finds no action for it. A task that builds one of them adds, in the same change:

1. its console command in the cli, which builds the command and its stable unit of work and calls `RunMaintenanceCommand`, in the maintenance process only;
2. its case in `packages/core/tests/Actions/MaintenanceAuthorizerTest.php` and a Postgres test of the console command, with a rerun that replays;
3. its line in the table above.

A command that is not in the list today, such as `actor.deactivate`, is added to `MaintenanceAuthorizer::COMMANDS` only with a reason in the same change: the list is what the operator can do, and it should stay the setup of an installation, not its administration, which belongs to staff members with roles.
