<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Routing;

use Cbox\Cms\Core\Routing\Boundary\PathExplanationJson;
use Cbox\Cms\Core\Tests\Routing\ResolveWorld as World;
use DateTimeImmutable;

/*
 * The JSON form of a resolution's explanation (GUARDRAILS 5): the one encoding cms:explain and the
 * explanation of GET /v1/resolve print. Every step the resolution reached is an object with every
 * key, the others are null, keys are sorted and times are UTC with microseconds.
 */

it('writes every step of a resolved placement with every key, sorted', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour', window: World::window(-1, 5));

    $document = PathExplanationJson::toArray($world->resolve('north.example', '/nyheder/harbour')->explanation);

    expect($document)->toBe([
        'canonical' => ['here' => true, 'placement' => World::PLACEMENT, 'url' => 'https://north.example/nyheder/harbour'],
        'mount' => null,
        'node' => ['kind' => 'section', 'node' => World::SECTION],
        'outcome' => 'resolved',
        'placement' => [
            'canonical' => true,
            'entry' => World::ENTRY,
            'looked_under' => World::SECTION,
            'placement' => World::PLACEMENT,
            'routable' => true,
            'slug' => 'harbour',
            'type' => World::ARTICLE,
        ],
        'route' => ['candidates' => ['/nyheder/harbour', '/nyheder', '/'], 'path' => '/nyheder/harbour', 'rest' => 'harbour', 'route' => '/nyheder'],
        'site' => ['handle' => 'north', 'host' => 'north.example', 'locale' => 'da', 'locale_published' => true, 'site' => World::NORTH_SITE],
        'visibility' => [
            'at' => '2026-03-10T12:00:00.000000Z',
            'decision' => 'visible',
            'lifecycle' => 'active',
            'release' => 'released',
            'rung' => 11,
            'stored' => 'live',
            'valid_until' => '2026-03-10T17:00:00.000000Z',
            'window' => ['from' => '2026-03-10T11:00:00.000000Z', 'until' => '2026-03-10T17:00:00.000000Z'],
        ],
    ]);
});

it('writes the mount of a second site and the canonical URL on the source\'s site', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour');

    $document = PathExplanationJson::toArray($world->resolve('south.example', '/national/harbour')->explanation);

    expect($document['mount'])->toBe(['mount' => World::MOUNT, 'source' => World::SECTION])
        ->and($document['node'])->toBe(['kind' => 'mount', 'node' => World::MOUNT])
        ->and($document['canonical'])->toBe(['here' => false, 'placement' => World::PLACEMENT, 'url' => 'https://north.example/nyheder/harbour'])
        ->and($document['visibility'])->toMatchArray([
            'valid_until' => null,
            'window' => ['from' => '2026-03-10T11:00:00.000000Z', 'until' => null],
        ]);
});

it('writes null for every step a resolution did not reach', function (): void {
    $document = PathExplanationJson::toArray(new World()->resolve('nowhere.example', '/nyheder/harbour')->explanation);

    expect($document)->toBe([
        'canonical' => null,
        'mount' => null,
        'node' => null,
        'outcome' => 'unknown_host',
        'placement' => null,
        'route' => null,
        'site' => ['handle' => null, 'host' => 'nowhere.example', 'locale' => 'da', 'locale_published' => false, 'site' => null],
        'visibility' => null,
    ]);
});

it('writes a placement it did not find, and a closed window with the rung that decided', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour', window: World::window(-5, -1));

    $missing = PathExplanationJson::toArray($world->resolve('north.example', '/nyheder/pier')->explanation);
    $closed = PathExplanationJson::toArray($world->resolve('north.example', '/nyheder/harbour')->explanation);

    expect($missing['outcome'])->toBe('no_placement')
        ->and($missing['placement'])->toBe(['canonical' => false, 'entry' => null, 'looked_under' => World::SECTION, 'placement' => null, 'routable' => false, 'slug' => 'pier', 'type' => null])
        ->and($closed['outcome'])->toBe('not_visible')
        ->and($closed['visibility'])->toMatchArray(['decision' => 'after_window', 'rung' => 9])
        ->and($closed['canonical'])->toBeNull();
});

it('writes a time in UTC with microseconds whatever its zone', function (): void {
    expect(PathExplanationJson::time(new DateTimeImmutable('2026-03-10T14:00:00.25+02:00')))->toBe('2026-03-10T12:00:00.250000Z');
});
