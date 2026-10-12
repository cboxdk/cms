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
