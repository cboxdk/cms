---
title: Recipes
weight: 40
description: Step-by-step recipes for the common tasks in this repository, for agents and people alike, each written in the same shape as a generator and each embedding the tests of a real run.
---

# Recipes

A recipe is the list of steps for a task that comes up again and again in this repository: which files to write, where they go, which commands write the rest, and which checks prove the result (GUARDRAILS 7.3, 11). They are written for coding agents first, and they read the same for a person.

Every recipe has the shape of a generator, so an agent following it and a generator given the same inputs write the same files:

1. **Inputs.** The few values the task is given, such as an owner and a handle. Everything else is derived from them.
2. **Files.** Every file the task writes or changes, with its path derived from the inputs, as `cms:generate` derives every path from the owner and handle of a type. A file marked *generated* is written by a command and never edited by hand.
3. **Steps.** The order to write the files in and the commands to run between them.
4. **Checks.** The tests that pin the result and the gates to run, and what fails when a step is skipped.
5. **Running example.** A task done this way in the repository, with its files and tests embedded byte for byte, so `composer docs:check` fails when the example and the recipe drift apart.

There is no generator for these tasks yet. When one comes, it takes a recipe's inputs and writes its files.

- [Add a content type](content-type.md): a type as one blueprint file, its generated code and migration, and the tests that list the workbench's types.
- [Write a hook in an addon](addon-hook.md): the addon package, its manifest, a hook class with its budget, and the tests with the testkit.
- [Add a kernel action](kernel-action.md): a command, its write action with `#[Action]` and surfaces, the mutation writer, the Actions-suite tests and the surface tests.
- [Expose a command in the panel](expose-command.md): the surfaces of a command's action, its JSON Schema and codec, the texts of its form, the permission a role needs, and the tests that hold the form to the schema and to the browser.
- [Add a panel page](panel-page.md): a page of the panel behind the login, the query it reads as the person with its schema and codec, its props, its React page, its navigation entry with the permission it needs, the point it declares, and the tests that hold it to REST and to the browser.

Before any recipe, read `CLAUDE.md` and `PROGRESS.md`, and work in a worktree of your own. Every recipe ends with `composer check`, and with every changed or new test recorded in `CHECKS-LOG.md` (GUARDRAILS 7.3).
