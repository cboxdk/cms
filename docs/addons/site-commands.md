---
title: Site commands
weight: 45
description: "How cms:sites:sync materialises the sites of cbox-cms.sites in the database through the kernel's command site.register, what a registration writes, why a drift of a site's locales is reported and never rewritten, and the rejections."
---

# Site commands

<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite -->

Sites and hosts are configuration kept in git and applied at deploy (PRD 11.14). `cbox-cms.sites` names each site by its handle, with the origin its canonical URLs are built from, the locales it publishes in and other hosts that resolve to it (see [configuration](../developers/configuration.md)). A grant needs a node, and a site's tree starts at its root node, so the database needs every configured site as rows: `cms:sites:sync` puts them there through the kernel command `site.register`, version 1.

| Command | What it does | Surfaces |
|---|---|---|
| `site.register` | registers a site with its root node, its locales and the route `/` in each | none; `cms:sites:sync` runs it as the installation operator |

The command, its event and its mutation are `#[Experimental]`.

<!-- example: examples/Unit/Structure/SiteCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Events\DatumKind;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Structure\Domain\Events\SiteRegistered;
use Cbox\Cms\Core\Structure\Domain\Events\SiteRegisteredV1;

// The configuration names the site north, served at https://north.example in Danish and English.
// cms:sites:sync finds no site with the handle north, so it runs site.register as the installation
// operator with a new site id and root node id. The command expects both the site and its handle
// absent, and site.registered tells about the site with its root node and its locales.

it('registers a site with its root node and locales, expecting the site and its handle absent', function (): void {
    $register = new RegisterSite(
        SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a01'),
        new SiteHandle('north'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a02'),
        [new Locale('da'), new Locale('en')],
    );
    $expected = $register->expectedVersions()->reads;

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey(), $expected))
        ->toBe(['site:01936f5e-8a2b-7c3d-9e4f-000000000a01', 'site_handle:north'])
        ->and(array_map(static fn (ReadVersion $read): bool => $read->existed(), $expected))->toBe([false, false]);
});

it('tells about a registered site with ids and locales alone', function (): void {
    $site = SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a01');
    $event = new SiteRegistered(1, new SiteRegisteredV1($site, NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a02'), [new Locale('da'), new Locale('en')]));
    $locales = $event->payload()->data()->get('locales');

    expect(SiteRegistered::type()->name)->toBe('site.registered')
        ->and($event->aggregate()->id->toString())->toBe($site->toString())
        ->and($locales->kind)->toBe(DatumKind::List)
        ->and(array_map(static fn (EventDatum $locale): string => $locale->asIdentifier()->value, $locales->items()))->toBe(['da', 'en']);
});

it('reports a site whose configured locales drifted as a data error that a retry does not fix', function (): void {
    $drift = ErrorCode::SiteLocalesDrift->entry();

    expect($drift->exit)->toBe(ExitCode::DataErr)
        ->and($drift->exit->value)->toBe(65)
        ->and($drift->retryable)->toBeFalse();
});
```

## cms:sites:sync

`php artisan cms:sites:sync` runs in the maintenance process at deploy, after `cms:install`, because each registration runs as the installation operator through the [maintenance pipeline](../developers/maintenance-commands.md). In the workbench, `composer dev:prepare` runs it after `cms:install`. For each site of `cbox-cms.sites`, in the configured order, it reads the registered site with the handle and:

- registers a site the database lacks: a new site id and root node id from the `IdGenerator`, the configured locales, one changeset per site;
- leaves a site registered with the same locales, in any order, alone, so a second run writes nothing;
- reports a site registered with other locales as `site_locales_drift` and writes nothing of it.

A drift or a rejection of one site never stops the others. The command prints one line per site, `registered`, `unchanged`, `drifted` or `rejected`; a `registered` and an `unchanged` line name the site's id and its root node, the node a first grant such as `cms:access:bootstrap`'s is given on. Each error goes on standard error with its catalog code. It exits 0 when every configured site is registered with its locales, 78 for a setting it cannot read, and otherwise with the exit code of the first error of the first site that did not sync: 65 for `site_locales_drift`, 78 for `installation_operator_missing` before `cms:install`.

The idempotency key of a registration is derived from the unit of work `sites:<handle>:<sha256 of the sorted locale tags joined by commas>`, the same for the same configuration, so a run that is repeated before its first run's receipt has expired replays it.

## Why a drift is not rewritten

The configuration says which locales a site publishes in, and content, placements, routes and grants in the database hang on those locales. Taking a locale away by rewriting the site from the configuration would leave them pointing at a locale the site no longer has, and adding one needs its root route. A site's locales change only through the pipeline, with the locale commands of a later block, which check what depends on them. Until then a drift is reported and left as it is: set the site's locales in the configuration back to the registered ones that the error lists.

## site.register

`Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite` takes:

- the `SiteId` of the new site and the `NodeId` of its root node, made by the caller, so a repeat with the same idempotency key is the same content;
- the `SiteHandle`, as the configuration names the site: a lowercase letter and at most 62 lowercase letters, digits or underscores;
- the locales, each a `Locale` once, at least one.

The command expects the site and its handle absent. The action reads both through the port `SiteDirectory`, and the kernel checks both at commit under its locks: a handle is an aggregate of its own, `site_handle:<handle>`, read as absent behind the commit's advisory lock, so two registrations of one handle commit one after the other, and the second is `version_conflict` instead of a unique violation.

## What a command writes

The plan is one `SiteRegistered` mutation (see [plans](plans.md)). The app role writes none of the structure tables: the writer calls the owner function `cms_structure_register_site`, which runs only in the transaction of a `site.register` changeset by the actor of the context. In the command's one transaction the commit writes:

| What | Where |
|---|---|
| the root node, of kind `site` at the top of the tree, at version 1 | `nodes` |
| the site with its handle and root node, at version 1 | `sites` |
| a row per locale, and in each locale the route `/` to the root node | `site_locales`, `node_routes` |
| the changeset, its audit row with the site's key, and its receipt | the changeset tables, `audit`, `receipts` |
| `site.registered`: the site, the root node and the locales, never the handle | `events` |

From the commit on, `path.resolve` finds the site at every host of its origin and aliases, and its root node is a node a grant can hold on.

## Rejections

| Code | When |
|---|---|
| `site_locales_drift` | `cms:sites:sync` found the site registered with other locales than the configured; nothing of it was written |
| `installation_operator_missing` | `cms:install` has not run, so no maintenance command can |
| `validation_failed` | the locales are empty or name one twice |
| `version_conflict` | a site has the id or the handle, or one was registered with it before the commit |

The [error reference](../reference/errors.md) explains each code.
