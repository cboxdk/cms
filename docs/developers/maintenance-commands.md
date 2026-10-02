---
title: Maintenance commands
weight: 30
description: How cms:install creates the installation operator once, why its genesis is the one exception to invariant 37, how the maintenance commands run as the operator through a pipeline that allows only a named list of commands, and how cms:access:bootstrap gives the first staff member access once.
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

The unit of work names the work, not the run, so a rerun of the same work replays the first run's receipt. Once the idempotency record has expired, after seven days, a rerun plans nothing for an aggregate that already exists. Use a unit that the same input always gives, such as `sites:<handle>:<hash of the configured locales>` for a site. A registration of a member of staff makes the actor's id first and uses `staff:<actor id>` for both its commands, because `actor.register` carries the new id, so a unit from the email alone would conflict with the content of an earlier run.

The maintenance pipeline is the kernel's command pipeline with one difference, its authorizer. `MaintenanceAuthorizer` allows a command only when all of these hold, and refuses every other call as [`unauthorized`](../reference/errors.md#unauthorized):

- the command is on its list, `MaintenanceAuthorizer::COMMANDS`;
- the envelope comes from the internal issuer `maintenance`;
- the principal is the installation operator, acting on behalf of no one, and the envelope's actor is the operator too.

The operator holds no grant, so the kernel's own authorizer refuses it every command, and the maintenance authorizer runs only in this pipeline, which `CoreServiceProvider` gives `RunMaintenanceCommand` alone.

## The list of commands

| Command | Added by | What it sets up |
|---|---|---|
| `actor.register` | block B1, task 4; run by `cms:staff:create` | a staff or service actor, pending |
| `actor.activate` | block B1, task 4; run by `cms:staff:create` | a pending actor, active |
| `site.register` | block B1, task 6 | a site with its root node and locales, from `cbox-cms.sites`, through `cms:sites:sync` ([site commands](../addons/site-commands.md)) |

`role.create` and `grant.assign` are not on the list. Only the access bootstrap runs them, through a pipeline of its own whose authorizer allows those two and nothing else (`MaintenanceAuthorizer::BOOTSTRAP_COMMANDS`); see [the access bootstrap](#the-access-bootstrap).

The list names all three, also the commands a later task of block B1 builds; a name the registry does not know never reaches the authorizer, because the pipeline finds no action for it. A task that builds one of them adds, in the same change:

1. its console command in the cli, which builds the command and its stable unit of work and calls `RunMaintenanceCommand`, in the maintenance process only;
2. its case in `packages/core/tests/Actions/MaintenanceAuthorizerTest.php` and a Postgres test of the console command, with a rerun that replays;
3. its line in the table above.

A command that is not in the list today, such as `actor.deactivate`, is added to `MaintenanceAuthorizer::COMMANDS` only with a reason in the same change: the list is what the operator can do, and it should stay the setup of an installation, not its administration, which belongs to staff members with roles.

## cms:staff:create

`php artisan cms:staff:create --email=<address> --name=<display name>` is the first maintenance command of the identity module: it registers a local member of staff as the operator, `actor.register`, then the credential, then `actor.activate`, and prints the actor's id. The password is read hidden from the terminal or with `--password-stdin` from standard input, never from an argument. [Local accounts](../security/local-accounts.md#creating-a-member-of-staff) describes the order, what a failure leaves and the exit codes. The command lives in the identity module, `Cbox\Cms\Identity\Cli\Console\StaffCreateCommand`, because the cli module may not use the identity module.

## cms:staff:reset-link and cms:identity:prune

`cms:staff:reset-link <email>` prints a password reset link for a local account, for an operator who hands it to the person another way than by mail, and `cms:identity:prune` removes the reset tokens used or expired more than 24 hours ago; the scheduler runs the prune every hour. Neither is a command through the pipeline: the tokens live in the credential store, not in the kernel's tables. Both run only in the maintenance process, the console process with the owner connection, and exit 78 with [`maintenance_process_required`](../reference/errors.md#maintenance_process_required) anywhere else. See [Local accounts](../security/local-accounts.md#resetting-a-password).

## The access bootstrap

A fresh installation has no staff member with a grant, and the escalation guard lets an actor give only what it holds itself (PRD 5.10, invariant 31), so nobody could give the first grant. `php artisan cms:access:bootstrap <actor> <node>` gives it once: it creates the bootstrap role and grants it to an active staff actor on a node, as the installation operator.

The bootstrap role has the handle in `cbox-cms.access.bootstrap_role` (`administrator` by default), every command and query of the registry as its permissions, and the ceiling sensitive. When a role with the handle exists with that ceiling and every one of those permissions, as after a run whose grant was rejected, the bootstrap uses it and creates none.

It runs in the maintenance process only, the console process with the owner connection, and through `RunMaintenanceCommand`, so the role and the grant are a `role.create` and a `grant.assign` changeset by the operator from the internal issuer `maintenance`, each with its audit row. Their pipeline has the `MaintenanceAuthorizer` built with `forAccessBootstrap()`, which allows those two commands to the operator and nothing else. That authorizer takes the place of the kernel's, so the escalation guard and the step-up that a grant of an administrative role needs do not apply: the operator holds nothing, and step-up is a person's fresh login in the panel, which the maintenance process does not have.

It is refused, with nothing committed:

| Exit | Code | When |
|---|---|---|
| 78 | [`maintenance_process_required`](../reference/errors.md#maintenance_process_required) | the process serves HTTP, runs queued jobs or has no owner connection |
| 77 | [`access_bootstrap_production`](../reference/errors.md#access_bootstrap_production) | the environment is production |
| 78 | [`installation_operator_missing`](../reference/errors.md#installation_operator_missing) | the installation has no operator; run `cms:install` first |
| 77 | [`access_bootstrap_done`](../reference/errors.md#access_bootstrap_done) | any staff member holds a grant, also one that has ended |
| 65 | [`validation_failed`](../reference/errors.md#validation_failed) | the node does not exist, or the actor is not a staff actor |
| 77 | [`actor_not_active`](../reference/errors.md#actor_not_active) | the staff actor is not active |
| 65 | [`access_bootstrap_role_conflict`](../reference/errors.md#access_bootstrap_role_conflict) | a role has the handle and is not the bootstrap role |
| 64 | | the actor or the node is not a UUIDv7 |

A command the pipeline rejects exits with the code of its first error. Two runs at once commit at most one grant: the grant's unit of work is `access-bootstrap:grant` for every run, so the second claims the same idempotency key, waits for the first and is rejected with [`idempotency_conflict`](../reference/errors.md#idempotency_conflict), or with [`idempotency_in_flight`](../reference/errors.md#idempotency_in_flight) while the first is still running.

The bootstrap never runs in production. PRD 5.10 lets full access in production come only from a role that only actors on the emergency access list can assign, and that list comes with block B6. Until then an installation in production has no way to give its first staff member access; the decision is recorded for review in `PROGRESS.md`.
