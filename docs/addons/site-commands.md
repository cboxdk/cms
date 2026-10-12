---
title: Site commands
weight: 45
description: "How cms:sites:sync applies the sites of cbox-cms.sites to the database through the kernel's commands site.register, site.add_locale and site.remove_locale, what each writes, why a locale a placement still uses is not retired, and the rejections."
---

# Site commands

<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite -->
<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\AddSiteLocale -->
<!-- extension-point: Cbox\Cms\Core\Structure\Domain\Commands\RemoveSiteLocale -->

Sites and hosts are configuration kept in git and applied at deploy (PRD 11.14). `cbox-cms.sites` names each site by its handle, with the origin its canonical URLs are built from, the locales it publishes in and other hosts that resolve to it (see [configuration](../developers/configuration.md)). A grant needs a node, and a site's tree starts at its root node, so the database needs every configured site as rows, and it needs the locales the configuration names: `cms:sites:sync` puts them there through three kernel commands, each version 1.

| Command | What it does | Surfaces |
|---|---|---|
| `site.register` | registers a site with its root node, its locales and the route `/` in each | none; `cms:sites:sync` runs it as the installation operator |
| `site.add_locale` | adds a locale to a registered site, with the route `/` in it | rest, inertia, mcp, cli; `cms:sites:sync` runs it too |
| `site.remove_locale` | retires a locale of a registered site | rest, inertia, mcp, cli; `cms:sites:sync` runs it too |

The commands, their events and their mutations are `#[Experimental]`.

<!-- example: examples/Unit/Structure/SiteCommandsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Errors\ExitCode;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Events\DatumKind;
use Cbox\Cms\Contracts\Events\EventDatum;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\SiteLocaleRemoved;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Structure\Domain\Commands\AddSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite;
use Cbox\Cms\Core\Structure\Domain\Commands\RemoveSiteLocale;
use Cbox\Cms\Core\Structure\Domain\Events\SiteLocaleRemoved as SiteLocaleRemovedEvent;
use Cbox\Cms\Core\Structure\Domain\Events\SiteLocaleRemovedV1;
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

// Later the configuration adds German and drops English. cms:sites:sync reads the site at version 1
// and runs site.add_locale for de, then site.remove_locale for en at version 2; a caller on a
// surface sends the same commands with the version it read. Both expect the site at that version.

it('adds and removes a locale of a registered site, expecting the site at the version read', function (): void {
    $site = SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a01');
    $add = new AddSiteLocale($site, new AggregateVersion(1), new Locale('de'));
    $remove = new RemoveSiteLocale($site, new AggregateVersion(2), new Locale('en'));

    expect(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey().' '.($read->version->value ?? '-'), $add->expectedVersions()->reads))
        ->toBe(['site:01936f5e-8a2b-7c3d-9e4f-000000000a01 1'])
        ->and(array_map(static fn (ReadVersion $read): string => $read->aggregate->aggregateKey().' '.($read->version->value ?? '-'), $remove->expectedVersions()->reads))
        ->toBe(['site:01936f5e-8a2b-7c3d-9e4f-000000000a01 2']);
});

it('tells about a removed locale with the site, its root node and the locale, which the invalidation purges the root node\'s key for', function (): void {
    $site = SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a01');
    $root = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000000a02');
    $mutation = new SiteLocaleRemoved($site, $root, new Locale('en'));
    $event = new SiteLocaleRemovedEvent(3, new SiteLocaleRemovedV1($site, $root, new Locale('en')));

    expect($mutation->aggregate()->aggregateKey())->toBe('site:01936f5e-8a2b-7c3d-9e4f-000000000a01')
        ->and(SiteLocaleRemovedEvent::type()->name)->toBe('site.locale_removed')
        ->and($event->aggregate()->version)->toBe(3)
        ->and($event->payload()->data()->get('root')->asIdentifier()->value)->toBe($root->toString())
        ->and($event->payload()->data()->get('locale')->asIdentifier()->value)->toBe('en');
});

it('refuses to retire a locale a placement still uses as a conflict that a retry does not fix', function (): void {
    $inUse = ErrorCode::SiteLocaleInUse->entry();

    expect($inUse->http)->toBe(HttpStatus::Conflict)
        ->and($inUse->exit)->toBe(ExitCode::DataErr)
        ->and($inUse->exit->value)->toBe(65)
        ->and($inUse->retryable)->toBeFalse();
});
```

## cms:sites:sync

`php artisan cms:sites:sync [--dry-run]` runs in the maintenance process at deploy, after `cms:install`, because each command runs as the installation operator through the [maintenance pipeline](../developers/maintenance-commands.md). In the workbench, `composer dev:prepare` runs it after `cms:install`. For each site of `cbox-cms.sites`, in the configured order, it reads the registered site with the handle and:

- registers a site the database lacks: a new site id and root node id from the `IdGenerator`, the configured locales, one changeset per site;
- leaves a site registered with the same locales, in any order, alone, so a second run writes nothing;
- reconciles a site registered with other locales: `site.add_locale` for each configured locale the site lacks, then `site.remove_locale` for each locale the site publishes in and the configuration no longer names, one changeset per locale. The additions go first, so a site that swaps its one locale never stands without one. A locale a placement still uses is reported as `site_locale_in_use` and left as it is, while the site's other changes are still applied.

A rejection of one site or one locale never stops the others. The command prints one line per site, `registered`, `unchanged`, `reconciled` or `rejected`; a line of a registered site names the site's id and its root node, the node a first grant such as `cms:access:bootstrap`'s is given on, and a `reconciled` line names each locale added or removed with its changeset. Each error goes on standard error with its catalog code. It exits 0 when every configured site is registered with its locales, 78 for a setting it cannot read, and otherwise with the exit code of the first error of the first site that did not sync: 65 for `site_locale_in_use`, 78 for `installation_operator_missing` before `cms:install`.

With `--dry-run` the command prints `dry run: nothing is written` and then what each site would get, `would register`, `unchanged`, `would reconcile` with `add` and `remove` per locale, or `would reject`; every command runs as a dry run through the pipeline, which validates and plans without committing, so a locale a placement still uses is reported there too, and nothing is written.

The idempotency key of a registration is derived from the unit of work `sites:<handle>:<sha256 of the sorted locale tags joined by commas>`, and of a locale change from `sites:<handle>:<added|removed>:<locale>@<site version>`, each the same for the same configuration and state, so a run that is repeated before its first run's receipts have expired replays them.

## site.register

`Cbox\Cms\Core\Structure\Domain\Commands\RegisterSite` takes:

- the `SiteId` of the new site and the `NodeId` of its root node, made by the caller, so a repeat with the same idempotency key is the same content;
- the `SiteHandle`, as the configuration names the site: a lowercase letter and at most 62 lowercase letters, digits or underscores;
- the locales, each a `Locale` once, at least one.

The command expects the site and its handle absent. The action reads both through the port `SiteDirectory`, and the kernel checks both at commit under its locks: a handle is an aggregate of its own, `site_handle:<handle>`, read as absent behind the commit's advisory lock, so two registrations of one handle commit one after the other, and the second is `version_conflict` instead of a unique violation.

## site.add_locale and site.remove_locale

`Cbox\Cms\Core\Structure\Domain\Commands\AddSiteLocale` and `RemoveSiteLocale` take the `SiteId`, the `AggregateVersion` of the site the caller read and the `Locale`. Both expect the site at that version, so a site changed meanwhile, or a site that does not exist, is `version_conflict`. Both are decided on the site's root node (PRD 5.10): the aggregates' authorization scope is the root in the locale, so the kernel's authorizer allows the command only through a role whose permissions name it and that reaches the root there; the installation operator, which holds no grant, runs them only through the maintenance authorizer.

An add refuses a locale the site publishes in already. A removal reads the site's use of the locale through the port `SiteLocaleUse`, the released placement locales in the locale below the site's root that are not withdrawn, counted past the actor's regions by the owner function `cms_structure_locale_use`, and refuses with `site_locale_in_use` while there is one, naming the count: a placement that is hidden, scheduled, live or expired would otherwise be left pointing at a locale the site no longer publishes in. The use is an aggregate the plan reads as absent, `site_locale_use:<site>:<locale>`, behind the commit's advisory lock and after the site's row, which `placement.create` holds `FOR SHARE` until it commits, so a placement created in the locale between the read and the commit makes the removal `version_conflict`, and a removal that commits first makes the placement `version_conflict`, never an orphan. The site's last locale and a locale the site does not publish in are `validation_failed`.

A removed locale is retired, not deleted: its row in `site_locales` gets `retired_at`, and the routes in the locale stay with it, so `site.add_locale` of the same locale brings the row and its routes back. Every reader of a site's locales, the directory, the placement reader and `path.resolve`, takes only the locales that are not retired, so the public resolves nothing below the site's root in the locale from the commit on.

## What a command writes

The plans are one `SiteRegistered`, `SiteLocaleAdded` or `SiteLocaleRemoved` mutation (see [plans](plans.md)). The writers run as the app role under the call's actor context: `sites` and `site_locales` take a row for an actor whose regions reach the site's root node, as `nodes` take a node, or in the transaction of a changeset of one of the three commands by the actor of the context whose audit row names the site, which is how the operator writes; a root node is taken only in the transaction of a `site.register` changeset. In the command's one transaction the commit writes:

| Command | What | Where |
|---|---|---|
| `site.register` | the root node, of kind `site` at the top of the tree, at version 1 | `nodes` |
| `site.register` | the site with its handle and root node, at version 1 | `sites` |
| `site.register` | a row per locale, and in each locale the route `/` to the root node | `site_locales`, `node_routes` |
| `site.add_locale` | the locale's row, or its retired row back, the route `/` in the locale when the site has none there, and the site's new version | `site_locales`, `node_routes`, `sites` |
| `site.remove_locale` | the locale's row retired, and the site's new version | `site_locales`, `sites` |
| each | the changeset, its audit row with the site's key, and its receipt | the changeset tables, `audit`, `receipts` |
| each | `site.registered`, `site.locale_added` or `site.locale_removed`: the site, the root node and the locales or the locale, never the handle | `events` |

From the commit on, `path.resolve` finds the site at every host of its origin and aliases in the locales it publishes in, and its root node is a node a grant can hold on. The invalidation subscriber purges the root node's key, `n-<root>`, for a locale added or removed, hard at the edge for a removal, so what was resolved below the root in the locale is gone.

## Rejections

| Code | When |
|---|---|
| `site_locale_in_use` | a placement locale in the locale, anywhere on the site, is not withdrawn; the error names how many |
| `installation_operator_missing` | `cms:install` has not run, so no maintenance command can |
| `unauthorized` | the actor's roles do not reach the site's root node in the locale with the permission |
| `validation_failed` | the locales of a registration are empty or name one twice; the locale is published already, not published, or the site's last |
| `version_conflict` | a site has the id or the handle, or one was registered with it before the commit; the site is at another version than the caller read, or does not exist; a placement was created in the locale before the removal's commit |

The [error reference](../reference/errors.md) explains each code.
