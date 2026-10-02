<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Routing;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Schema\Stages;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\ConfiguredSite;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementMatch;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\InvalidRoutingValue;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\SiteHosts;
use Cbox\Cms\Core\Routing\Domain\SiteOrigin;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Core\Routing\Domain\VisibilityPrecedence;
use DateTimeImmutable;

/*
 * The values of path.resolve (PRD 5.9, 6.6, 8.10 point 7): the host, the request path and its
 * prefixes, the site handle and origin, the configured sites, the canonical path, the precedence
 * and the result's content.
 */

it('takes a host in lower case with an optional port, and refuses anything else', function (): void {
    expect(new Host('North.Example')->value)->toBe('north.example')
        ->and(new Host('localhost:8000')->value)->toBe('localhost:8000')
        ->and(new Host('a.b')->equals(new Host('A.B')))->toBeTrue();

    foreach (['', 'north.example/', '-north.example', 'north..example', 'north.example:0', 'north.example:65536', 'https://north.example', 'nørth.example', str_repeat('a', 250).'.dk'] as $invalid) {
        expect(static fn (): Host => new Host($invalid))->toThrow(InvalidRoutingValue::class);
    }
});

it('takes a path of segments, lists its prefixes the longest first and gives the rest after a route', function (): void {
    $path = new RequestPath('/nyheder/sport/match');

    expect($path->prefixes())->toBe(['/nyheder/sport/match', '/nyheder/sport', '/nyheder', '/'])
        ->and(new RequestPath('/')->prefixes())->toBe(['/'])
        ->and($path->rest('/'))->toBe('nyheder/sport/match')
        ->and($path->rest('/nyheder'))->toBe('sport/match')
        ->and($path->rest('/nyheder/sport/match'))->toBe('')
        ->and($path->rest('/nyhed'))->toBeNull()
        ->and(new RequestPath('/'.implode('/', array_fill(0, RequestPath::MAX_SEGMENTS, 'a')))->value)->toStartWith('/a/');

    foreach (['', 'nyheder', '/nyheder/', '//nyheder', '/ny heder', '/nyheder/./x', '/../x', '/'.implode('/', array_fill(0, RequestPath::MAX_SEGMENTS + 1, 'a')), '/'.str_repeat('a', RequestPath::MAX_BYTES), "/\xff"] as $invalid) {
        expect(static fn (): RequestPath => new RequestPath($invalid))->toThrow(InvalidRoutingValue::class);
    }
});

it('takes a site handle as the sites table holds it and an origin of a scheme and a host', function (): void {
    $origin = new SiteOrigin('HTTPS://North.Example:8443');

    expect(new SiteHandle('north_2')->value)->toBe('north_2')
        ->and($origin->value)->toBe('https://north.example:8443')
        ->and($origin->host->value)->toBe('north.example:8443')
        ->and($origin->url('/nyheder/harbour'))->toBe('https://north.example:8443/nyheder/harbour');

    foreach (['North', '2north', '', str_repeat('a', 64)] as $invalid) {
        expect(static fn (): SiteHandle => new SiteHandle($invalid))->toThrow(InvalidRoutingValue::class);
    }

    foreach (['north.example', 'ftp://north.example', 'https://north.example/', 'https://north.example/x', 'https://north.example?x=1', 'https://'] as $invalid) {
        expect(static fn (): SiteOrigin => new SiteOrigin($invalid))->toThrow(InvalidRoutingValue::class);
    }
});

it('maps each configured host to its one site and refuses a host of two sites or a site configured twice', function (): void {
    $north = new ConfiguredSite(new SiteHandle('north'), new SiteOrigin('https://north.example'), [new Host('www.north.example'), new Host('north.example')]);
    $south = new ConfiguredSite(new SiteHandle('south'), new SiteOrigin('https://south.example'));
    $sites = new SiteHosts([$north, $south]);

    expect(array_map(static fn (Host $host): string => $host->value, $north->hosts))->toBe(['north.example', 'www.north.example'])
        ->and($north->hosts[0])->toBe($north->origin->host)
        ->and($sites->serving(new Host('www.north.example')))->toBe($north)
        ->and($sites->serving(new Host('south.example')))->toBe($south)
        ->and($sites->serving(new Host('west.example')))->toBeNull()
        ->and($sites->named(new SiteHandle('south')))->toBe($south)
        ->and($sites->named(new SiteHandle('west')))->toBeNull()
        ->and(new SiteHosts()->sites)->toBe([])
        ->and(static fn (): SiteHosts => new SiteHosts([$north, new ConfiguredSite(new SiteHandle('west'), new SiteOrigin('https://west.example'), [new Host('www.north.example')])]))
        ->toThrow(InvalidRoutingValue::class, 'The host "www.north.example" is configured for both the sites "north" and "west"')
        ->and(static fn (): SiteHosts => new SiteHosts([$south, $south]))->toThrow(InvalidRoutingValue::class, 'The site "south" is configured twice.');
});

it('writes the path of a canonical placement from its node\'s route and its slug', function (): void {
    $match = static fn (?string $route): CanonicalMatch => new CanonicalMatch(
        PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003541'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003512'),
        new Slug('harbour'),
        $route === null ? null : new SiteHandle('north'),
        $route,
    );

    expect($match('/nyheder')->path())->toBe('/nyheder/harbour')
        ->and($match('/')->path())->toBe('/harbour')
        ->and($match(null)->path())->toBeNull();
});

it('names the rung of PRD 6.6 each decision is, and only live is visible', function (): void {
    expect(array_map(static fn (VisibilityDecision $decision): int => $decision->rung(), VisibilityDecision::cases()))->toBe([2, 3, 5, 8, 9, 9, 9, 11])
        ->and(array_values(array_filter(VisibilityDecision::cases(), static fn (VisibilityDecision $decision): bool => $decision->visible())))->toBe([VisibilityDecision::Visible]);
});

it('decides from the window at the time, not from the state stored when it was set', function (): void {
    $at = new DateTimeImmutable('2026-03-10T12:00:00Z');
    $placement = static fn (Visibility $stored, ?TimeWindow $window): PlacementMatch => new PlacementMatch(
        PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003541'),
        EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003531'),
        $stored,
        $window,
        true,
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003521'),
        EntryLifecycle::Active,
        ReleaseState::Released,
    );
    $opened = VisibilityPrecedence::decide($placement(Visibility::Scheduled, new TimeWindow(new DateTimeImmutable('2026-03-10T11:00:00Z'), new DateTimeImmutable('2026-03-10T13:00:00Z'))), Stages::DraftRelease, $at);
    $ended = VisibilityPrecedence::decide($placement(Visibility::Live, new TimeWindow(until: new DateTimeImmutable('2026-03-10T12:00:00Z'))), Stages::DraftRelease, $at);
    $always = VisibilityPrecedence::decide($placement(Visibility::Live, TimeWindow::always()), Stages::DraftRelease, $at);
    $unread = VisibilityPrecedence::decide(new PlacementMatch(
        PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003541'),
        EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003531'),
        Visibility::Live,
        TimeWindow::always(),
        true,
        null,
        null,
        null,
    ), null, $at);

    expect([$opened->decision, $opened->validUntil])->toEqual([VisibilityDecision::Visible, new DateTimeImmutable('2026-03-10T13:00:00Z')])
        ->and([$ended->decision, $ended->validUntil])->toBe([VisibilityDecision::AfterWindow, null])
        ->and([$always->decision, $always->validUntil])->toBe([VisibilityDecision::Visible, null])
        ->and($unread->decision)->toBe(VisibilityDecision::EntryNotActive)
        ->and($unread->validUntil)->toBeNull();
});

it('holds at most one entry as its content and hands the same explanation back with it', function (): void {
    $content = new ReadContent(
        EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003531'),
        NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003512'),
        TypeId::fromString('01936f5e-8a2b-7c3d-9e4f-000000003521'),
    );
    $explanation = new PathExplanation(ResolveOutcome::NoRoute, new SiteStep(new Host('north.example'), new Locale('da'), null, null, false));
    $empty = new ResolvedPath(null, $explanation);
    $held = $empty->withContents([$content]);

    expect($empty->contents())->toBe([])
        ->and($empty->resolved())->toBeFalse()
        ->and($held->contents())->toBe([$content])
        ->and($held->explanation)->toBe($explanation)
        ->and($held->withContents([])->content)->toBeNull();
});

it('takes a host name of exactly 253 characters and the port 65535, the largest of each', function (): void {
    $name = implode('.', [str_repeat('a', 63), str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', 61)]);

    expect(strlen($name))->toBe(Host::MAX_NAME_LENGTH)
        ->and(new Host($name)->value)->toBe($name)
        ->and(new Host('example.dk:65535')->value)->toBe('example.dk:65535')
        ->and(fn (): Host => new Host($name.'e'))->toThrow(InvalidRoutingValue::class)
        ->and(fn (): Host => new Host('example.dk:65536'))->toThrow(InvalidRoutingValue::class);
});
