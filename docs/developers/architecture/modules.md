---
title: Modules and their boundaries
weight: 32
description: Why the modules stay in packages/<module>/src, the boundaries the Arch suite keeps between them, what the package requires and suggests, and how a first-party module is added.
---

# Modules and their boundaries

The module table is in `CLAUDE.md` under "Hvor ting bor". This page holds the reasons and the rules behind it.

## Why the layout stays

Why the layout stays `packages/<module>/src` after the merge into one package: the directory names the module, and the checks and the published paths read it. The Arch suite scopes by `packages/*/src`, `phpstan.neon` lists each module's directories and `rector.php` globs `packages/*/{bin,config,database,src,tests}`, the content type scan reads `packages/{contracts,core,testkit,generators,http,cli,mcp,identity,panel}/src`, gate 10's inventory by `packages/*/src` and `packages/*/resources/schemas`, mutation on changed files by `packages/*/src`, `Progress\Domain\CheckPaths` by the `tests` directories, and the selftest plants in `packages/core/src/Selftest`. Addons include `vendor/cboxdk/cms/packages/testkit/config/phpstan.neon` (and `pint.json` and `rector.php`), and applications' blueprint files point at `vendor/cboxdk/cms/packages/contracts/resources/schemas/blueprint.v1.json`. Moving the code to `src/<Module>` would change all of these and every file's history for no change in behaviour.

## The boundaries

The boundaries. No package boundary keeps the modules apart, so `tests/Arch/ModulesTest.php` does, with the rules in `Cbox\Cms\Tests\Support\Arch\ModuleDependencies`: the repository is the one library `cboxdk/cms` with no manifest per module; every directory below `packages/` is a module in `ModuleDependencies::MODULES`, autoloaded from its `src` and its tests from its `tests`; the modules' providers are in `extra.laravel.providers`; contracts uses no other module and no package; core, http, cli and mcp never use the testkit, the generators or identity, identity never uses the testkit or the generators, the generators never use the testkit, the panel may use core, http and identity but never cli, mcp, the testkit or the generators, no module uses the panel, and the testkit uses no module but contracts (`FORBIDDEN_MODULES`); no module uses the tests, the tooling, the workbench or the examples (`DEVELOPMENT_CODE`); every package a module uses is in `require` or `suggest`, and the production modules (`PRODUCTION`: contracts, core, http, cli, mcp, identity, panel) use none that is only suggested. A class counts as used when src names it in code, and a class outside the package is mapped to the installed package that holds its file. The last tests plant each kind of violation in a scratch directory and check that it is reported. The layers of the table below and the content type rule hold in every module.

## Dependencies

Dependencies. `require` holds only what the production modules use: PHP, `composer-runtime-api`, `illuminate/console`, `illuminate/contracts`, `illuminate/database`, `illuminate/redis`, `illuminate/support`, `psr/clock`, `psr/log`, `symfony/console` and `symfony/process`. The testkit's and the generators' heavy dependencies, `phpstan/phpstan`, `larastan/larastan`, `rector/rector`, `driftingly/rector-laravel`, `laravel/pint`, `orchestra/testbench`, `phpunit/phpunit`, `symfony/yaml` and `opis/json-schema`, are in `suggest`, each with the reason, and in this repository's `require-dev`; an application or addon puts the ones it uses in its own `require-dev`, so they never reach production. The generators check theirs before they read a blueprint (`Schema\Boundary\SuggestedPackages`, `generate_invalid_config` with the `composer require --dev` command). `docs/requirements.md` restates both lists, and a test holds it to `composer.json`.

## New modules

New modules. A first-party module is a new namespace in the same package, never a package of its own: MCP as `Cbox\Cms\Mcp` in `packages/mcp`, the panel's PHP side as `Cbox\Cms\Panel` in `packages/panel` (its React code is `js/panel`, published to npm), and a module that owns content types, such as end-user accounts with the member profile (PRD 15.1), as `Cbox\Cms\Members` in `packages/members`, with its types in its own schema files. Adding one means its PSR-4 roots in `autoload` and `autoload-dev`, its provider in `extra.laravel.providers` and `testbench.yaml`, its entry in `ModuleDependencies::MODULES`, `FORBIDDEN_MODULES` and, when it runs in production, `PRODUCTION`, its directories in `phpstan.neon`'s `paths`, and its directory in `ContentTypeScan` when it is kernel code. E-commerce is an addon with a package of its own that requires `cboxdk/cms` and uses only `#[Stable]` and `#[Experimental]` API (GUARDRAILS 2.3).
