<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Query as QueryAttribute;
use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Actions\ResolvePathAction;
use Cbox\Cms\Core\Routing\Domain\Dto\CanonicalStep;
use Cbox\Cms\Core\Routing\Domain\Dto\MountStep;
use Cbox\Cms\Core\Routing\Domain\Dto\NodeStep;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\ResolvedPath;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\NodeKind;
use Cbox\Cms\Core\Routing\Domain\Queries\ResolvePath;
use Cbox\Cms\Core\Routing\Domain\ReleaseState;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\SiteHandle;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use Cbox\Cms\Core\Tests\Routing\ResolveWorld as World;
use DateTimeImmutable;
use ReflectionClass;

/*
 * path.resolve's action with fakes (GUARDRAILS 5 and 9, PRD 5.8, 5.9, 6.6): it maps the host to a
 * configured site, finds the longest route prefix of the path, looks the rest up as a slug below
 * the node, below the source for a mount, decides the precedence at the Clock's time and, for a
 * visible placement, the canonical URL; each answer carries the typed explanation of every step it
 * reached, and the entry whenever its placement was read.
 */

const RESOLVE_OTHER_ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000003532';

const RESOLVE_WITHDRAWN = '01936f5e-8a2b-7c3d-9e4f-000000003543';

/**
 * The content keys of the answer, as the query pipeline attaches them.
 *
 * @return list<string>
 */
function resolvedKeys(ResolvedPath $result): array
{
    return array_merge(...array_map(
        static fn (ReadContent $content): array => array_map(static fn (DependencyKey $key): string => $key->toString(), $content->contentKeys()),
        $result->contents(),
    ));
}

it('resolves a direct placement and explains each step: site, route prefix, node, placement, visibility and canonical URL', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour', window: World::window(-1, 5));

    $result = $world->resolve('north.example', '/nyheder/harbour');
    $explanation = $result->explanation;

    expect($result->outcome())->toBe(ResolveOutcome::Resolved)
        ->and($result->resolved())->toBeTrue()
        ->and($explanation->site)->toEqual(new SiteStep(new Host('north.example'), new Locale('da'), new SiteHandle('north'), SiteId::fromString(World::NORTH_SITE), true))
        ->and([$explanation->route?->route, $explanation->route?->rest, $explanation->route?->candidates])->toBe(['/nyheder', 'harbour', ['/nyheder/harbour', '/nyheder', '/']])
        ->and($explanation->node)->toEqual(new NodeStep(NodeId::fromString(World::SECTION), NodeKind::Section))
        ->and($explanation->mount)->toBeNull()
        ->and($explanation->placement)->toEqual(new PlacementStep(
            NodeId::fromString(World::SECTION),
            new Slug('harbour'),
            PlacementId::fromString(World::PLACEMENT),
            EntryId::fromString(World::ENTRY),
            TypeId::fromString(World::ARTICLE),
            true,
            true,
        ))
        ->and($explanation->visibility?->decision)->toBe(VisibilityDecision::Visible)
        ->and($explanation->visibility?->decision->rung())->toBe(11)
        ->and($explanation->visibility?->at)->toEqual(new DateTimeImmutable(World::NOW))
        ->and([$explanation->visibility?->lifecycle, $explanation->visibility?->release, $explanation->visibility?->stored])->toBe([EntryLifecycle::Active, ReleaseState::Released, Visibility::Live])
        ->and($explanation->visibility?->validUntil)->toEqual(new DateTimeImmutable('2026-03-10T17:00:00Z'))
        ->and($explanation->canonical)->toEqual(new CanonicalStep(PlacementId::fromString(World::PLACEMENT), 'https://north.example/nyheder/harbour', true))
        ->and(resolvedKeys($result))->toBe(['e-'.World::ENTRY, 'n-'.World::SECTION])
        ->and($result->content?->fields)->toEqual(World::released())
        ->and($world->reader->reads)->toBe(4);
});

it('resolves an alias host to its site and builds the canonical URL from the site\'s origin, not the host', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour');

    $result = $world->resolve('WWW.North.Example', '/nyheder/harbour');

    expect($result->outcome())->toBe(ResolveOutcome::Resolved)
        ->and($result->explanation->site->host->value)->toBe('www.north.example')
        ->and($result->explanation->canonical?->url)->toBe('https://north.example/nyheder/harbour')
        ->and($result->explanation->visibility?->validUntil)->toBeNull();
});

it('resolves a mount on a second site below its source, identical to the source, which stays canonical', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour');

    $result = $world->resolve('south.example', '/national/harbour');
    $explanation = $result->explanation;

    expect($result->outcome())->toBe(ResolveOutcome::Resolved)
        ->and($explanation->site->handle?->value)->toBe('south')
        ->and($explanation->site->site?->toString())->toBe(World::SOUTH_SITE)
        ->and([$explanation->route?->route, $explanation->route?->rest])->toBe(['/national', 'harbour'])
        ->and($explanation->node)->toEqual(new NodeStep(NodeId::fromString(World::MOUNT), NodeKind::Mount))
        ->and($explanation->mount)->toEqual(new MountStep(NodeId::fromString(World::MOUNT), NodeId::fromString(World::SECTION)))
        ->and($explanation->placement?->lookedUnder->toString())->toBe(World::SECTION)
        ->and($explanation->placement?->placement?->toString())->toBe(World::PLACEMENT)
        ->and($explanation->placement?->canonical)->toBeTrue()
        ->and($explanation->visibility?->decision)->toBe(VisibilityDecision::Visible)
        ->and($explanation->canonical)->toEqual(new CanonicalStep(PlacementId::fromString(World::PLACEMENT), 'https://north.example/nyheder/harbour', false))
        ->and(resolvedKeys($result))->toBe(['e-'.World::ENTRY, 'n-'.World::SECTION]);
});

it('explains an unknown path: no placement with the slug, a rest that is not one slug, and a path that names the node', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour');

    $missing = $world->resolve('north.example', '/nyheder/nothing');
    $deeper = $world->resolve('north.example', '/nyheder/harbour/more');
    $node = $world->resolve('north.example', '/nyheder');
    $root = $world->resolve('north.example', '/');

    expect($missing->outcome())->toBe(ResolveOutcome::NoPlacement)
        ->and($missing->explanation->placement)->toEqual(new PlacementStep(NodeId::fromString(World::SECTION), new Slug('nothing')))
        ->and($missing->explanation->visibility)->toBeNull()
        ->and($missing->explanation->canonical)->toBeNull()
        ->and($missing->content)->toBeNull()
        ->and($missing->contents())->toBe([])
        ->and($deeper->outcome())->toBe(ResolveOutcome::NoSlug)
        ->and([$deeper->explanation->route?->route, $deeper->explanation->route?->rest])->toBe(['/nyheder', 'harbour/more'])
        ->and($deeper->explanation->placement)->toBeNull()
        ->and($node->outcome())->toBe(ResolveOutcome::NoSlug)
        ->and($node->explanation->route?->rest)->toBe('')
        ->and($node->explanation->node?->node->toString())->toBe(World::SECTION)
        ->and($root->outcome())->toBe(ResolveOutcome::NoSlug)
        ->and($root->explanation->node?->kind)->toBe(NodeKind::Site);
});

it('explains a host no site serves, a site with no row, a locale the site does not publish and a locale without routes', function (): void {
    $world = new World;

    $host = $world->resolve('elsewhere.example', '/nyheder/harbour');
    $site = $world->resolve('west.example', '/nyheder/harbour');
    $locale = $world->resolve('south.example', '/national/harbour', 'en');
    $route = $world->resolve('north.example', '/nyheder/harbour', 'en');

    expect($host->outcome())->toBe(ResolveOutcome::UnknownHost)
        ->and($host->explanation->site)->toEqual(new SiteStep(new Host('elsewhere.example'), new Locale('da'), null, null, false))
        ->and($host->explanation->route)->toBeNull()
        ->and($site->outcome())->toBe(ResolveOutcome::UnknownSite)
        ->and($site->explanation->site->handle?->value)->toBe('west')
        ->and($site->explanation->site->site)->toBeNull()
        ->and($locale->outcome())->toBe(ResolveOutcome::LocaleNotPublished)
        ->and($locale->explanation->site->site?->toString())->toBe(World::SOUTH_SITE)
        ->and($locale->explanation->site->localePublished)->toBeFalse()
        ->and($locale->explanation->route)->toBeNull()
        ->and($route->outcome())->toBe(ResolveOutcome::NoRoute)
        ->and($route->explanation->site->localePublished)->toBeTrue()
        ->and([$route->explanation->route?->route, $route->explanation->route?->rest])->toBe([null, null])
        ->and($route->explanation->node)->toBeNull();
});

it('does not show a placement whose window has closed, and says so at the Clock\'s time with the entry\'s content keys', function (): void {
    $world = new World()->place(World::PLACEMENT, World::SECTION, 'harbour', window: World::window(-5, -1));

    $result = $world->resolve('north.example', '/nyheder/harbour');
    $visibility = $result->explanation->visibility;

    expect($result->outcome())->toBe(ResolveOutcome::NotVisible)
        ->and($result->resolved())->toBeFalse()
        ->and($visibility?->decision)->toBe(VisibilityDecision::AfterWindow)
        ->and($visibility?->decision->rung())->toBe(9)
        ->and($visibility?->stored)->toBe(Visibility::Live)
        ->and($visibility?->window)->toEqual(World::window(-5, -1))
        ->and($visibility?->validUntil)->toBeNull()
        ->and($result->explanation->canonical)->toBeNull()
        ->and(resolvedKeys($result))->toBe(['e-'.World::ENTRY, 'n-'.World::SECTION])
        ->and($world->reader->reads)->toBe(2);

    $world->clock->set(new DateTimeImmutable('2026-03-10T08:00:00Z'));

    expect($world->resolve('north.example', '/nyheder/harbour')->outcome())->toBe(ResolveOutcome::Resolved);
});

it('explains a non-canonical placement with the canonical URL of the entry\'s canonical placement on the other site', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::SECTION, 'harbour')
        ->place(World::SOUTH_PLACEMENT, World::LOCAL, 'havnen', canonical: false);

    $result = $world->resolve('south.example', '/lokalt/havnen');
    $canonical = $world->resolve('north.example', '/nyheder/harbour');

    expect($result->outcome())->toBe(ResolveOutcome::Resolved)
        ->and($result->explanation->mount)->toBeNull()
        ->and($result->explanation->placement?->placement?->toString())->toBe(World::SOUTH_PLACEMENT)
        ->and($result->explanation->placement?->canonical)->toBeFalse()
        ->and($result->explanation->canonical)->toEqual(new CanonicalStep(PlacementId::fromString(World::PLACEMENT), 'https://north.example/nyheder/harbour', false))
        ->and(resolvedKeys($result))->toBe(['e-'.World::ENTRY, 'n-'.World::LOCAL])
        ->and($canonical->explanation->canonical?->here)->toBeTrue();
});

it('decides the precedence strongest first: entry, variant, placement withdrawn, release, then the window', function (): void {
    $cases = [
        'archived' => [static fn (World $world): World => $world->place(World::PLACEMENT, World::SECTION, 'harbour', visibility: Visibility::Withdrawn, lifecycle: EntryLifecycle::Archived, release: ReleaseState::Withdrawn), VisibilityDecision::EntryNotActive],
        'variant' => [static fn (World $world): World => $world->place(World::PLACEMENT, World::SECTION, 'harbour', visibility: Visibility::Withdrawn, release: ReleaseState::Withdrawn), VisibilityDecision::VariantWithdrawn],
        'placement' => [static fn (World $world): World => $world->place(World::PLACEMENT, World::SECTION, 'harbour', visibility: Visibility::Withdrawn, release: ReleaseState::Unreleased), VisibilityDecision::PlacementWithdrawn],
        'unreleased' => [static fn (World $world): World => $world->place(World::PLACEMENT, World::SECTION, 'harbour', release: ReleaseState::Unreleased), VisibilityDecision::NoReleasedRevision],
        'no head' => [static fn (World $world): World => $world->place(World::PLACEMENT, World::SECTION, 'harbour', release: null), VisibilityDecision::NoReleasedRevision],
        'hidden' => [static fn (World $world): World => $world->place(World::PLACEMENT, World::SECTION, 'harbour', visibility: Visibility::Hidden), VisibilityDecision::PlacementHidden],
        'before' => [static fn (World $world): World => $world->place(World::PLACEMENT, World::SECTION, 'harbour', visibility: Visibility::Scheduled, window: World::window(2, 4)), VisibilityDecision::BeforeWindow],
        'stages none' => [static fn (World $world): World => $world->place(World::PLACEMENT, World::SECTION, 'harbour', type: World::READING, release: null), VisibilityDecision::Visible],
    ];

    foreach ($cases as $name => [$state, $expected]) {
        expect($state(new World)->resolve('north.example', '/nyheder/harbour')->explanation->visibility?->decision)->toBe($expected, $name);
    }

    expect(new World()->place(World::PLACEMENT, World::SECTION, 'harbour', visibility: Visibility::Scheduled, window: World::window(2, 4))
        ->resolve('north.example', '/nyheder/harbour')->explanation->visibility?->validUntil)->toEqual(new DateTimeImmutable('2026-03-10T14:00:00Z'));
});

it('reads a withdrawn placement as withdrawn, and an entry the reader cannot read as not active, without its content', function (): void {
    $withdrawn = new World()->place(RESOLVE_WITHDRAWN, World::SECTION, 'gone', visibility: Visibility::Withdrawn, canonical: false)->resolve('north.example', '/nyheder/gone');
    $unreadable = new World()->place(World::PLACEMENT, World::SECTION, 'harbour', type: null, lifecycle: null, release: null)->resolve('north.example', '/nyheder/harbour');

    expect($withdrawn->outcome())->toBe(ResolveOutcome::NotVisible)
        ->and($withdrawn->explanation->visibility?->decision)->toBe(VisibilityDecision::PlacementWithdrawn)
        ->and($withdrawn->explanation->visibility?->decision->rung())->toBe(5)
        ->and($unreadable->outcome())->toBe(ResolveOutcome::NotVisible)
        ->and($unreadable->explanation->visibility?->decision)->toBe(VisibilityDecision::EntryNotActive)
        ->and($unreadable->explanation->placement?->type)->toBeNull()
        ->and($unreadable->content)->toBeNull();
});

it('does not resolve a placement of a type without URLs', function (): void {
    $result = new World()->place(World::PLACEMENT, World::SECTION, 'note', type: World::NOTE)->resolve('north.example', '/nyheder/note');

    expect($result->outcome())->toBe(ResolveOutcome::NotRoutable)
        ->and($result->explanation->placement?->routable)->toBeFalse()
        ->and($result->explanation->visibility)->toBeNull()
        ->and(resolvedKeys($result))->toBe(['e-'.World::ENTRY, 'n-'.World::SECTION]);
});

it('gives no canonical URL when the canonical placement\'s node has no route', function (): void {
    $world = new World()
        ->place(World::PLACEMENT, World::STORE, 'harbour', canonical: true, entry: RESOLVE_OTHER_ENTRY)
        ->place(World::SOUTH_PLACEMENT, World::LOCAL, 'harbour', canonical: false, entry: RESOLVE_OTHER_ENTRY);

    $result = $world->resolve('south.example', '/lokalt/harbour');

    expect($result->outcome())->toBe(ResolveOutcome::Resolved)
        ->and($result->explanation->canonical)->toEqual(new CanonicalStep(PlacementId::fromString(World::PLACEMENT), null, false));
});

it('costs at most one route, one placement, its released row and one canonical placement, whatever the query', function (): void {
    $query = new ResolvePath(new Host('north.example'), new Locale('da'), new RequestPath('/nyheder/harbour'));

    expect(new World()->action()->cost($query)->units)->toBe(ResolvePathAction::COST)
        ->and(ResolvePathAction::COST)->toBe(4);
});

it('is version 1 of path.resolve', function (): void {
    $query = new ResolvePath(new Host('north.example'), new Locale('da'), new RequestPath('/'));
    $declared = new ReflectionClass($query)->getAttributes(QueryAttribute::class)[0]->newInstance();

    expect([$declared->name, $declared->version])->toBe(['path.resolve', 1]);
});
