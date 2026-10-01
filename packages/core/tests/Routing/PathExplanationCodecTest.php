<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Routing;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Core\Codecs\Boundary\Generated\PathExplanationCodecV1;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\VisibilityStep;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Core\Tests\Routing\ResolveWorld as World;
use DateTimeImmutable;

/*
 * The JSON form of a resolution's explanation (GUARDRAILS 2.2, 5), path-explanation.v1.json, written
 * by its generated codec PathExplanationCodecV1: the one encoding cms:explain and the explanation of
 * GET /v1/resolve print. Every step the resolution reached is an object with every key, the others
 * are null, keys are sorted and times are UTC with microseconds, and the codec reads back what it
 * wrote.
 */

/**
 * The explanation as its codec writes it, decoded into arrays, after a round trip through the codec.
 *
 * @return array<array-key, mixed>
 */
function explanationDocument(PathExplanation $explanation): array
{
    $codec = new PathExplanationCodecV1;
    $json = $codec->encode($explanation, ClassificationAccess::Public);

    expect($codec->encode($codec->decode($json, ClassificationAccess::Public), ClassificationAccess::Public))->toBe($json);

    $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    return is_array($document) ? $document : [];
}

it('writes every step of a resolved placement with every key, sorted', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour', window: World::window(-1, 5));

    $document = explanationDocument($world->resolve('north.example', '/nyheder/harbour')->explanation);

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
        'route' => ['path' => '/nyheder/harbour', 'rest' => 'harbour', 'route' => '/nyheder'],
        'site' => ['handle' => 'north', 'host' => 'north.example', 'locale' => 'da', 'locale_published' => true, 'site' => World::NORTH_SITE],
        'visibility' => [
            'at' => '2026-03-10T12:00:00.000000Z',
            'decision' => 'visible',
            'lifecycle' => 'active',
            'release' => 'released',
            'stored' => 'live',
            'valid_until' => '2026-03-10T17:00:00.000000Z',
            'window' => ['from' => '2026-03-10T11:00:00.000000Z', 'until' => '2026-03-10T17:00:00.000000Z'],
        ],
    ]);
});

it('writes the mount of a second site and the canonical URL on the source\'s site', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour');

    $document = explanationDocument($world->resolve('south.example', '/national/harbour')->explanation);

    expect($document['mount'])->toBe(['mount' => World::MOUNT, 'source' => World::SECTION])
        ->and($document['node'])->toBe(['kind' => 'mount', 'node' => World::MOUNT])
        ->and($document['canonical'])->toBe(['here' => false, 'placement' => World::PLACEMENT, 'url' => 'https://north.example/nyheder/harbour'])
        ->and($document['visibility'])->toMatchArray([
            'valid_until' => null,
            'window' => ['from' => '2026-03-10T11:00:00.000000Z', 'until' => null],
        ]);
});

it('writes null for every step a resolution did not reach', function (): void {
    $document = explanationDocument(new World()->resolve('nowhere.example', '/nyheder/harbour')->explanation);

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

it('writes a placement it did not find, and a closed window with the decision', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour', window: World::window(-5, -1));

    $missing = explanationDocument($world->resolve('north.example', '/nyheder/pier')->explanation);
    $closed = explanationDocument($world->resolve('north.example', '/nyheder/harbour')->explanation);

    expect($missing['outcome'])->toBe('no_placement')
        ->and($missing['placement'])->toBe(['canonical' => false, 'entry' => null, 'looked_under' => World::SECTION, 'placement' => null, 'routable' => false, 'slug' => 'pier', 'type' => null])
        ->and($closed['outcome'])->toBe('not_visible')
        ->and($closed['visibility'])->toMatchArray(['decision' => 'after_window'])
        ->and($closed['canonical'])->toBeNull();
});

it('writes a time in UTC with microseconds whatever its zone', function (): void {
    $explanation = new PathExplanation(
        ResolveOutcome::NotVisible,
        new SiteStep(new Host('north.example'), new Locale('da'), null, null, true),
        visibility: new VisibilityStep(VisibilityDecision::BeforeWindow, new DateTimeImmutable('2026-03-10T14:00:00.25+02:00'), null, null, Visibility::Scheduled, new TimeWindow(new DateTimeImmutable('2026-03-10T16:00:00+02:00')), new DateTimeImmutable('2026-03-10T16:00:00+02:00')),
    );

    expect(explanationDocument($explanation)['visibility'] ?? null)->toMatchArray([
        'at' => '2026-03-10T12:00:00.250000Z',
        'valid_until' => '2026-03-10T14:00:00.000000Z',
        'window' => ['from' => '2026-03-10T14:00:00.000000Z', 'until' => null],
    ]);
});
