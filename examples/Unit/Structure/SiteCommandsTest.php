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
