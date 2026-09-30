<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Cache\Fragment;
use Cbox\Cms\Contracts\Cache\FragmentPurge;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Errors\HttpStatus;
use Cbox\Cms\Contracts\Identity\TransportCredential;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Core\Delivery\Actions\DeliverPath;
use Cbox\Cms\Core\Delivery\Domain\AnswerFormat;
use Cbox\Cms\Core\Delivery\Domain\DeliverySource;
use Cbox\Cms\Core\Delivery\Domain\Dto\Delivery;
use Cbox\Cms\Core\Delivery\Domain\Dto\DeliverySettings;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Tests\Delivery\DeliveryWorld;
use Cbox\Cms\Core\Tests\Routing\ResolveWorld;
use DateInterval;
use DateTimeImmutable;

/*
 * The delivery API's resolve with fakes (PRD 8.9, 8.10, 8.12, GUARDRAILS 9): a resolved path is
 * answered with the entry's record at the public classification access and stored as a fragment
 * with its content keys, and the next request is served from the fragment without a read; 404, 410
 * and 421 and a malformed request are answered with a problem; the lifetime is capped by the
 * placement's window; a build under a purge fence is sent with no-store; and the explanation is for
 * an actor whose access is at least internal, never stored.
 */

const DELIVERED_ENTRY = ResolveWorld::ENTRY;

/**
 * @return list<string>
 */
function deliveredKeys(Delivery $delivery): array
{
    return array_map(static fn (DependencyKey $key): string => $key->toString(), $delivery->contentKeys);
}

function deliveredFragment(DeliveryWorld $world, string $host, string $path): ?Fragment
{
    return $world->fragments->read(DeliverPath::fragmentKey(new ResolvePath(new Host($host), new Locale('da'), new RequestPath($path))));
}

it('answers a resolved path with its record at the public access, and stores it as a fragment with its content keys', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour');

    $delivery = $world->deliver('north.example', 'da', '/nyheder/harbour');
    $fragment = deliveredFragment($world, 'north.example', '/nyheder/harbour');

    expect($delivery->status)->toBe(HttpStatus::Ok)
        ->and($delivery->format)->toBe(AnswerFormat::Record)
        ->and($delivery->body)->toContain('record {"cms_id":"'.DELIVERED_ENTRY.'","title":"The harbour opens"}')
        ->and($delivery->body)->toContain('meta app:article da https://north.example/nyheder/harbour')
        ->and($delivery->body)->not->toContain('Embargoed')
        ->and(deliveredKeys($delivery))->toBe(['e-'.DELIVERED_ENTRY, 'n-'.ResolveWorld::SECTION])
        ->and([$delivery->cache->shared, $delivery->cache->maxAge, $delivery->cache->staleWhileRevalidate, $delivery->cache->staleIfError])->toBe([true, 600, 30, 3600])
        ->and($delivery->source)->toBe(DeliverySource::Origin)
        ->and($fragment?->builtAt->value)->toBe(DeliveryWorld::POSITION)
        ->and(array_map(static fn (DependencyKey $key): string => $key->toString(), $fragment->dependencies ?? []))->toBe(deliveredKeys($delivery))
        ->and($fragment?->validUntil)->toEqual(new DateTimeImmutable(ResolveWorld::NOW)->modify('+600 seconds'));
});

it('answers the same placement through a mount on a second site, with the same content keys', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour');

    $delivery = $world->deliver('south.example', 'da', '/national/harbour');

    expect($delivery->status)->toBe(HttpStatus::Ok)
        ->and($delivery->body)->toContain('record {"cms_id":"'.DELIVERED_ENTRY.'","title":"The harbour opens"}')
        ->and($delivery->body)->toContain('https://north.example/nyheder/harbour')
        ->and(deliveredKeys($delivery))->toBe(['e-'.DELIVERED_ENTRY, 'n-'.ResolveWorld::SECTION]);
});

it('serves the next request from the fragment without running the query pipeline, for what is left of its lifetime', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour');

    $first = $world->deliver('north.example', 'da', '/nyheder/harbour');
    $world->resolve->clock->advance(new DateInterval('PT100S'));
    $second = $world->deliver('north.example', 'da', '/nyheder/harbour');

    expect($world->resolutions())->toBe(1)
        ->and($second->source)->toBe(DeliverySource::Fragment)
        ->and([$second->status, $second->format, $second->body])->toBe([$first->status, $first->format, $first->body])
        ->and(deliveredKeys($second))->toBe(deliveredKeys($first))
        ->and([$second->cache->shared, $second->cache->maxAge, $second->cache->staleWhileRevalidate])->toBe([true, 500, 30]);
});

it('serves a fragment for its whole seconds left, and with no-store when less than one is left', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour');

    $world->deliver('north.example', 'da', '/nyheder/harbour');
    $world->resolve->clock->set(new DateTimeImmutable('2026-03-10T12:01:40.500000Z'));
    $partly = $world->deliver('north.example', 'da', '/nyheder/harbour');
    $world->resolve->clock->set(new DateTimeImmutable('2026-03-10T12:09:59.500000Z'));
    $last = $world->deliver('north.example', 'da', '/nyheder/harbour');

    expect([$partly->source, $partly->cache->maxAge])->toBe([DeliverySource::Fragment, 499])
        ->and([$last->source, $last->cache->shared])->toBe([DeliverySource::Fragment, false])
        ->and($world->resolutions())->toBe(1);
});

it('sends an answer that holds for less than a second with no-store and stores nothing', function (): void {
    $world = new DeliveryWorld;
    $now = new DateTimeImmutable(ResolveWorld::NOW);
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour', window: new TimeWindow($now->modify('-1 hour'), $now->modify('+500 milliseconds')));

    $delivery = $world->deliver('north.example', 'da', '/nyheder/harbour');

    expect([$delivery->status, $delivery->cache->shared])->toBe([HttpStatus::Ok, false])
        ->and(deliveredFragment($world, 'north.example', '/nyheder/harbour'))->toBeNull();
});

it('keeps an answer at most the configured lifetime when its window ends later, without stale directives', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour', window: ResolveWorld::window(-1, 5));

    $delivery = $world->deliver('north.example', 'da', '/nyheder/harbour');

    expect([$delivery->cache->shared, $delivery->cache->maxAge, $delivery->cache->stale()])->toBe([true, 600, false]);
});

it('builds the answer again once the fragment has expired, and when the fragment cannot be read', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour');

    $world->deliver('north.example', 'da', '/nyheder/harbour');
    $world->resolve->clock->advance(new DateInterval('PT600S'));
    $expired = $world->deliver('north.example', 'da', '/nyheder/harbour');
    $key = DeliverPath::fragmentKey(new ResolvePath(new Host('north.example'), new Locale('da'), new RequestPath('/nyheder/harbour')));
    $world->fragments->write(new Fragment($key, 'not a stored answer', [DependencyKey::entry(EntryId::fromString(DELIVERED_ENTRY))], new CommitPosition('1'), $world->resolve->clock->now()->modify('+1 minute')));
    $damaged = $world->deliver('north.example', 'da', '/nyheder/harbour');

    expect($world->resolutions())->toBe(3)
        ->and([$expired->source, $expired->status])->toBe([DeliverySource::Origin, HttpStatus::Ok])
        ->and([$damaged->source, $damaged->status])->toBe([DeliverySource::Origin, HttpStatus::Ok]);
});

it('caps the lifetime of a visible placement at the end of its window and sends no stale directive before the removal', function (): void {
    $world = new DeliveryWorld;
    $world->settings = new DeliverySettings(maxAge: 86400);
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour', window: ResolveWorld::window(-1, 5));

    $delivery = $world->deliver('north.example', 'da', '/nyheder/harbour');

    expect($delivery->status)->toBe(HttpStatus::Ok)
        ->and([$delivery->cache->shared, $delivery->cache->maxAge, $delivery->cache->stale()])->toBe([true, 5 * 3600, false])
        ->and(deliveredFragment($world, 'north.example', '/nyheder/harbour')?->validUntil)->toEqual(new DateTimeImmutable(ResolveWorld::NOW)->modify('+5 hours'));
});

it('answers a path where nothing is placed with path_not_found, tagged with the node the slug was looked up under', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour');

    $delivery = $world->deliver('north.example', 'da', '/nyheder/nothing');

    expect($delivery->status)->toBe(HttpStatus::NotFound)
        ->and($delivery->format)->toBe(AnswerFormat::Problem)
        ->and($delivery->body)->toContain('problem path_not_found Nothing is placed at /nyheder/nothing in da at north.example.')
        ->and(deliveredKeys($delivery))->toBe(['n-'.ResolveWorld::SECTION])
        ->and([$delivery->cache->shared, $delivery->cache->maxAge, $delivery->cache->stale()])->toBe([true, 600, false])
        ->and(deliveredFragment($world, 'north.example', '/nyheder/nothing'))->toBeInstanceOf(Fragment::class);
});

it('answers a window that has not opened with path_not_found, its content keys and a lifetime capped at the opening', function (): void {
    $world = new DeliveryWorld;
    $world->settings = new DeliverySettings(maxAge: 86400);
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour', window: ResolveWorld::window(2, null));

    $delivery = $world->deliver('north.example', 'da', '/nyheder/harbour');

    expect($delivery->status)->toBe(HttpStatus::NotFound)
        ->and($delivery->body)->toContain('problem path_not_found')
        ->and($delivery->body)->toContain('before_window')
        ->and($delivery->body)->not->toContain('record')
        ->and(deliveredKeys($delivery))->toBe(['e-'.DELIVERED_ENTRY, 'n-'.ResolveWorld::SECTION])
        ->and([$delivery->cache->shared, $delivery->cache->maxAge, $delivery->cache->stale()])->toBe([true, 2 * 3600, false]);
});

it('answers a window that has closed with path_not_found and its content keys, for the configured lifetime', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour', window: ResolveWorld::window(-5, -1));

    $delivery = $world->deliver('north.example', 'da', '/nyheder/harbour');

    expect($delivery->status)->toBe(HttpStatus::NotFound)
        ->and($delivery->body)->toContain('after_window')
        ->and(deliveredKeys($delivery))->toBe(['e-'.DELIVERED_ENTRY, 'n-'.ResolveWorld::SECTION])
        ->and([$delivery->cache->shared, $delivery->cache->maxAge, $delivery->cache->stale()])->toBe([true, 600, false]);
});

it('answers what was withdrawn with path_gone, tagged with its content keys', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour', visibility: Visibility::Withdrawn, canonical: false);

    $delivery = $world->deliver('north.example', 'da', '/nyheder/harbour');

    expect($delivery->status)->toBe(HttpStatus::Gone)
        ->and($delivery->body)->toContain('problem path_gone')
        ->and(deliveredKeys($delivery))->toBe(['e-'.DELIVERED_ENTRY, 'n-'.ResolveWorld::SECTION])
        ->and($delivery->cache->shared)->toBeTrue();
});

it('answers a host no configured site is served at with host_not_configured, before any fragment or read', function (): void {
    $world = new DeliveryWorld;

    $delivery = $world->deliver('elsewhere.example', 'da', '/nyheder/harbour');

    expect($delivery->status)->toBe(HttpStatus::MisdirectedRequest)
        ->and($delivery->body)->toContain('problem host_not_configured No configured site is served at elsewhere.example.')
        ->and($delivery->contentKeys)->toBe([])
        ->and($delivery->cache->shared)->toBeFalse()
        ->and($world->resolutions())->toBe(0);
});

it('refuses a request whose parameters are missing or malformed with validation_failed, before any read', function (?string $site, ?string $locale, ?string $path, ?string $debug): void {
    $world = new DeliveryWorld;

    $delivery = $world->deliver($site, $locale, $path, $debug);

    expect($delivery->status)->toBe(HttpStatus::UnprocessableContent)
        ->and($delivery->body)->toContain('problem validation_failed')
        ->and($delivery->cache->shared)->toBeFalse()
        ->and($world->resolutions())->toBe(0)
        ->and($world->documents->written)->toBe(1);
})->with([
    'no site' => [null, 'da', '/nyheder/harbour', null],
    'an empty locale' => ['north.example', '', '/nyheder/harbour', null],
    'no path' => ['north.example', 'da', null, null],
    'a host that is not a name' => ['north example', 'da', '/nyheder/harbour', null],
    'a locale that is not one' => ['north.example', 'da dk', '/nyheder/harbour', null],
    'a path with a trailing slash' => ['north.example', 'da', '/nyheder/harbour/', null],
    'debug that is not 1' => ['north.example', 'da', '/nyheder/harbour', 'yes'],
]);

it('sends a build under a purge fence with no-store and stores nothing', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour');
    $world->fragments->purge(new FragmentPurge(DependencyKey::entry(EntryId::fromString(DELIVERED_ENTRY)), new CommitPosition(DeliveryWorld::POSITION), $world->resolve->clock->now()->modify('+1 minute')));

    $delivery = $world->deliver('north.example', 'da', '/nyheder/harbour');
    $again = $world->deliver('north.example', 'da', '/nyheder/harbour');

    expect($delivery->status)->toBe(HttpStatus::Ok)
        ->and($delivery->cache->shared)->toBeFalse()
        ->and(deliveredKeys($delivery))->toBe(['e-'.DELIVERED_ENTRY, 'n-'.ResolveWorld::SECTION])
        ->and(deliveredFragment($world, 'north.example', '/nyheder/harbour'))->toBeNull()
        ->and($again->source)->toBe(DeliverySource::Origin)
        ->and($world->resolutions())->toBe(2);
});

it('gives an actor whose access is at least internal the explanation, never stored or shared', function (): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour');

    $explained = $world->deliver('north.example', 'da', '/nyheder/harbour', '1', $world->staff());
    $missing = $world->deliver('north.example', 'da', '/nyheder/nothing', '1', $world->staff());

    expect($explained->status)->toBe(HttpStatus::Ok)
        ->and($explained->format)->toBe(AnswerFormat::Explanation)
        ->and($explained->body)->toContain('explanation resolved')
        ->and($explained->body)->toContain('record {"cms_id":"'.DELIVERED_ENTRY.'","title":"The harbour opens"}')
        ->and($explained->cache->shared)->toBeFalse()
        ->and(deliveredKeys($explained))->toBe(['e-'.DELIVERED_ENTRY, 'n-'.ResolveWorld::SECTION])
        ->and(deliveredFragment($world, 'north.example', '/nyheder/harbour'))->toBeNull()
        ->and([$missing->status, $missing->format])->toBe([HttpStatus::NotFound, AnswerFormat::Explanation])
        ->and($missing->body)->toContain('explanation no_placement')
        ->and($missing->body)->toContain('problem path_not_found');
});

it('refuses the explanation to the anonymous principal and to an actor whose access is public, and to a credential that does not verify', function (string $caller, HttpStatus $status): void {
    $world = new DeliveryWorld;
    $world->resolve->place(ResolveWorld::PLACEMENT, ResolveWorld::SECTION, 'harbour');
    $credential = match ($caller) {
        'member' => $world->member(),
        'malformed' => new TransportCredential('not-a-token'),
        default => null,
    };

    $delivery = $world->deliver('north.example', 'da', '/nyheder/harbour', '1', $credential);

    expect($delivery->status)->toBe($status)
        ->and($delivery->format)->toBe(AnswerFormat::Problem)
        ->and($delivery->body)->not->toContain('explanation resolved')
        ->and($delivery->body)->not->toContain('record')
        ->and($delivery->cache->shared)->toBeFalse()
        ->and(deliveredFragment($world, 'north.example', '/nyheder/harbour'))->toBeNull();
})->with([
    'no credential' => ['anonymous', HttpStatus::Forbidden],
    'a member' => ['member', HttpStatus::Forbidden],
    'a malformed credential' => ['malformed', HttpStatus::Unauthorized],
]);
