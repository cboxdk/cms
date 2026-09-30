<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Placements;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Ids\SiteId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Placements\Domain\CanonicalPlacementRef;
use Cbox\Cms\Core\Placements\Domain\CanonicalRule;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementState;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementCreated;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementCreatedV1;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementVisibilityChanged;
use Cbox\Cms\Core\Placements\Domain\Events\PlacementVisibilityChangedV1;
use Cbox\Cms\Core\Placements\Domain\PlacementSlugRef;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use DateTimeImmutable;

/*
 * The placement domain (PRD 5.7, 6.4, 6.7, invariant 14): the state a window gives at a time and
 * its next transition, the canonical rule, the aggregate keys of a slug and of an entry's canonical
 * placement, and the events placement.created and placement.visibility_changed, which carry ids,
 * states and times and never a slug (invariant 10).
 */

const DOMAIN_NOW = '2026-03-10T12:00:00+00:00';

function domainAt(string $offset = '+0 hours'): DateTimeImmutable
{
    return new DateTimeImmutable(DOMAIN_NOW)->modify($offset);
}

function domainState(string $suffix, Visibility $visibility, ?TimeWindow $window, bool $canonical = false): PlacementState
{
    return new PlacementState(PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007'.$suffix), new AggregateVersion(1), $visibility, $window, $canonical);
}

it('derives the state and the next transition from a window at a time', function (): void {
    $now = domainAt();
    $scheduled = new TimeWindow(domainAt('+2 hours'), domainAt('+5 hours'));
    $live = new TimeWindow(domainAt('-2 hours'), domainAt('+5 hours'));
    $expired = new TimeWindow(domainAt('-5 hours'), domainAt('-2 hours'));

    expect(Visibility::of(null, $now))->toBe(Visibility::Hidden)
        ->and(Visibility::of($scheduled, $now))->toBe(Visibility::Scheduled)
        ->and(Visibility::of($live, $now))->toBe(Visibility::Live)
        ->and(Visibility::of(TimeWindow::always(), $now))->toBe(Visibility::Live)
        ->and(Visibility::of($expired, $now))->toBe(Visibility::Expired)
        ->and(Visibility::of(new TimeWindow($now), $now))->toBe(Visibility::Live)
        ->and(Visibility::of(new TimeWindow(null, $now), $now))->toBe(Visibility::Expired)
        ->and(Visibility::nextTransition($scheduled, $now))->toEqual(domainAt('+2 hours'))
        ->and(Visibility::nextTransition($live, $now))->toEqual(domainAt('+5 hours'))
        ->and(Visibility::nextTransition(TimeWindow::always(), $now))->toBeNull()
        ->and(Visibility::nextTransition($expired, $now))->toBeNull()
        ->and(Visibility::nextTransition(null, $now))->toBeNull();
});

it('decides visibility at a time from the window, never for a hidden or withdrawn placement', function (): void {
    $window = new TimeWindow(domainAt('-1 hour'), domainAt('+1 hour'));

    expect(Visibility::Scheduled->visibleAt($window, domainAt()))->toBeTrue()
        ->and(Visibility::Live->visibleAt($window, domainAt('+2 hours')))->toBeFalse()
        ->and(Visibility::Withdrawn->visibleAt($window, domainAt()))->toBeFalse()
        ->and(Visibility::Hidden->visibleAt($window, domainAt()))->toBeFalse()
        ->and(Visibility::Live->visibleAt(null, domainAt()))->toBeFalse();
});

it('keeps the canonical placement while it is visible, and moves the flag to the first visible one otherwise', function (): void {
    $rule = new CanonicalRule;
    $open = new TimeWindow(domainAt('-1 hour'));
    $later = new TimeWindow(domainAt('+1 hour'));

    expect($rule->choose([domainState('01', Visibility::Live, $open), domainState('02', Visibility::Live, $open, true)], domainAt())?->toString())->toEndWith('02')
        ->and($rule->choose([domainState('03', Visibility::Live, $open), domainState('01', Visibility::Hidden, null, true), domainState('02', Visibility::Live, $open)], domainAt())?->toString())->toEndWith('02')
        ->and($rule->choose([domainState('01', Visibility::Scheduled, $later, true), domainState('02', Visibility::Hidden, null)], domainAt())?->toString())->toEndWith('01')
        ->and($rule->choose([domainState('02', Visibility::Hidden, null), domainState('01', Visibility::Scheduled, $later)], domainAt())?->toString())->toEndWith('01')
        ->and($rule->choose([domainState('01', Visibility::Scheduled, $later, true)], domainAt('+2 hours'))?->toString())->toEndWith('01');
});

it('never makes a withdrawn placement canonical, and none when every placement is withdrawn', function (): void {
    $rule = new CanonicalRule;

    expect($rule->choose([domainState('01', Visibility::Withdrawn, TimeWindow::always()), domainState('02', Visibility::Hidden, null)], domainAt())?->toString())->toEndWith('02')
        ->and($rule->choose([domainState('01', Visibility::Withdrawn, TimeWindow::always())], domainAt()))->toBeNull()
        ->and($rule->choose([], domainAt()))->toBeNull();
});

it('keys a slug by node, locale and slug, and the canonical placement by entry and locale', function (): void {
    $node = NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007a1');
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007e1');

    expect(new PlacementSlugRef($node, new Locale('en-gb'), new Slug('a:b'))->aggregateKey())->toBe('placement_slug:01936f5e-8a2b-7c3d-9e4f-0000000007a1:en-GB:a:b')
        ->and(new CanonicalPlacementRef($entry, new Locale('da'))->aggregateKey())->toBe('placement_canonical:01936f5e-8a2b-7c3d-9e4f-0000000007e1:da');
});

it('tells that a placement was created and that its window was set, with ids, states and times', function (): void {
    $placement = PlacementId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007c1');
    $entry = EntryId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007e1');
    $created = new PlacementCreated(1, new PlacementCreatedV1($placement, $entry, NodeId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007a1'), SiteId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000007b1')));
    $changed = new PlacementVisibilityChanged(2, new PlacementVisibilityChangedV1($placement, $entry, new Locale('da'), Visibility::Hidden, Visibility::Live, domainAt('-1 hour'), null, null));
    $data = $changed->payload()->data();

    expect(PlacementCreated::type())->toEqual(new EventType('placement.created', 1))
        ->and(PlacementVisibilityChanged::type())->toEqual(new EventType('placement.visibility_changed', 1))
        ->and([$created->aggregate()->type->value, $created->aggregate()->id->value, $created->aggregate()->version])->toBe(['placement', '01936f5e-8a2b-7c3d-9e4f-0000000007c1', 1])
        ->and(array_keys($created->payload()->data()->fields()))->toBe(['entry', 'node', 'placement', 'site'])
        ->and($changed->aggregate()->version)->toBe(2)
        ->and(array_keys($data->fields()))->toBe(['entry', 'live_from', 'live_until', 'locale', 'next_transition_at', 'placement', 'previous', 'visibility'])
        ->and($data->get('locale')->asIdentifier()->value)->toBe('da')
        ->and([$data->get('previous')->asEnumValue(), $data->get('visibility')->asEnumValue()])->toBe(['hidden', 'live'])
        ->and($data->get('live_from')->asTime())->toEqual(domainAt('-1 hour'))
        ->and($data->get('live_until')->isNull())->toBeTrue();
});
