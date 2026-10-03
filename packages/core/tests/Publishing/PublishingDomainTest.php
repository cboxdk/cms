<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Publishing;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\RevisionNumber;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Content\VariantKey;
use Cbox\Cms\Contracts\Content\VariantRef;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Events\EventType;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\AuthorizationScope;
use Cbox\Cms\Contracts\Pipeline\AuthorizationTarget;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementCanonicalSet;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementClosed;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementWindowSet;
use Cbox\Cms\Contracts\Plans\Mutations\VariantUnreleased;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\BecomesVisible;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Core\Entries\Actions\VariantReleasePlanner;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredEntry;
use Cbox\Cms\Core\Entries\Domain\Dto\StoredHead;
use Cbox\Cms\Core\Entries\Domain\Events\VariantUnreleased as VariantUnreleasedEvent;
use Cbox\Cms\Core\Entries\Domain\Events\VariantUnreleasedV1;
use Cbox\Cms\Core\Placements\Actions\PlacementPlanner;
use Cbox\Cms\Core\Placements\Domain\CanonicalPlacementRef;
use Cbox\Cms\Core\Placements\Domain\Dto\LocalePlacements;
use Cbox\Cms\Core\Placements\Domain\Dto\PlacementState;
use Cbox\Cms\Core\Placements\Domain\Dto\StoredPlacement;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Publishing\Actions\PublishEntryAction;
use Cbox\Cms\Core\Publishing\Actions\UnpublishEntryAction;
use Cbox\Cms\Core\Publishing\Domain\Commands\PublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Commands\UnpublishEntry;
use Cbox\Cms\Core\Publishing\Domain\Dto\PublishEntryAggregates;
use Cbox\Cms\Core\Publishing\Domain\Dto\UnpublishEntryAggregates;
use Cbox\Cms\Core\Publishing\Domain\VisibilityReport;
use Cbox\Cms\Core\Tests\Entries\EntryActionWorld;
use Cbox\Cms\Core\Tests\Entries\Fakes\FakeEntryReader;
use Cbox\Cms\Core\Tests\Entries\NoteType;
use Cbox\Cms\Core\Tests\Placements\Fakes\FakePlacementReader;
use Cbox\Cms\Core\Tests\Publishing\PublishingActionWorld as World;
use Cbox\Cms\Testkit\Clock\FakeClock;
use Cbox\Cms\Testkit\Schema\FakeTypeCatalog;
use DateTimeImmutable;
use LogicException;

/*
 * The parts entry.publish and entry.unpublish compose (PRD 6.4): when a placement's own state and
 * window make it visible, the planner's close of every placement visible now or later with the
 * canonical move it causes, the planner's unrelease, the report of what a plan makes visible, the
 * commands' expected versions, what the actions read, the event variant.unreleased, and the
 * actions' refusal to plan from reads the kernel rules out.
 */

function domainState(string $placement, Visibility $visibility, ?TimeWindow $window, bool $canonical = false, int $version = 1): PlacementState
{
    return new PlacementState(World::placement($placement), new AggregateVersion($version), $visibility, $window, $canonical);
}

/**
 * @param  list<BecomesVisible>  $visible
 * @return list<string>
 */
function domainVisible(array $visible): array
{
    return array_map(static fn (BecomesVisible $item): string => $item->placement->toString().' '.$item->from->format('H:i'), $visible);
}

it('says from when a placement is visible: now, at its window\'s start, or never', function (): void {
    $at = World::at(0);

    expect(Visibility::Live->visibleFrom(World::window(-1), $at))->toEqual($at)
        ->and(Visibility::Scheduled->visibleFrom(World::window(-1), $at))->toEqual($at)
        ->and(Visibility::Scheduled->visibleFrom(World::window(3), $at))->toEqual(World::at(3))
        ->and(Visibility::Live->visibleFrom(World::window(-3, -1), $at))->toBeNull()
        ->and(Visibility::Hidden->visibleFrom(null, $at))->toBeNull()
        ->and(Visibility::Withdrawn->visibleFrom(TimeWindow::always(), $at))->toBeNull();
});

it('closes every placement that is visible now or later, keeps the others, and moves the canonical flag only when the rule says so', function (): void {
    $planner = new PlacementPlanner;
    $da = new Locale('da');
    $all = new LocalePlacements(World::entry(), $da, [
        domainState(World::HOME_PLACEMENT, Visibility::Live, World::window(-1), true),
        domainState(World::AWAY_PLACEMENT, Visibility::Scheduled, World::window(2)),
        domainState(World::FAR_PLACEMENT, Visibility::Withdrawn, null),
    ]);
    $none = new LocalePlacements(World::entry(), $da, [domainState(World::HOME_PLACEMENT, Visibility::Hidden, null, true)]);
    $withoutCanonical = new LocalePlacements(World::entry(), $da, [
        domainState(World::HOME_PLACEMENT, Visibility::Hidden, null),
        domainState(World::AWAY_PLACEMENT, Visibility::Live, World::window(-1)),
    ]);

    expect($planner->close($all, World::at(0)))->toEqual(new Plan(new PlacementClosed(World::placement(), $da), new PlacementClosed(World::placement(World::AWAY_PLACEMENT), $da)))
        ->and($planner->close($none, World::at(0))->isEmpty())->toBeTrue()
        ->and($planner->close($withoutCanonical, World::at(0)))->toEqual(new Plan(new PlacementClosed(World::placement(World::AWAY_PLACEMENT), $da), new PlacementCanonicalSet(World::placement(), $da, true)));
});

it('plans the unrelease of the released revision, and nothing for a variant that has none', function (): void {
    $planner = new VariantReleasePlanner;
    $entry = static fn (?StoredHead $head): StoredEntry => new StoredEntry(World::entry(), EntryActionWorld::type(), World::node(World::HOME), new AggregateVersion(2), $head);

    expect($planner->unrelease($entry(new StoredHead(new AggregateVersion(4), new RevisionNumber(3), new RevisionNumber(4), new RevisionNumber(4)))))
        ->toEqual(new Plan(new VariantUnreleased(World::entry(), VariantKey::shared(), new RevisionNumber(4))))
        ->and($planner->unrelease($entry(new StoredHead(new AggregateVersion(1), RevisionNumber::first(), RevisionNumber::first(), null)))->isEmpty())->toBeTrue()
        ->and(static fn (): Plan => $planner->unrelease($entry(null)))->toThrow(LogicException::class, 'read as absent');
});

it('reports the placements a plan makes visible, now or at another time, and those a release shows', function (): void {
    $report = new VisibilityReport;
    $da = new Locale('da');
    $at = World::at(0);
    $everywhere = [new LocalePlacements(World::entry(), $da, [
        domainState(World::HOME_PLACEMENT, Visibility::Scheduled, World::window(4), true),
        domainState(World::AWAY_PLACEMENT, Visibility::Live, World::window(-2)),
        domainState(World::FAR_PLACEMENT, Visibility::Hidden, null),
    ])];
    $window = new Plan(new PlacementWindowSet(World::placement(), $da, new TimeWindow($at)));
    $describe = domainVisible(...);

    expect($describe($report->of(false, true, $everywhere, $window, $at)))->toBe([World::HOME_PLACEMENT.' 12:00', World::AWAY_PLACEMENT.' 12:00'])
        ->and($describe($report->of(true, true, $everywhere, $window, $at)))->toBe([World::HOME_PLACEMENT.' 12:00'])
        ->and($describe($report->of(false, true, $everywhere, new Plan, $at)))->toBe([World::HOME_PLACEMENT.' 16:00', World::AWAY_PLACEMENT.' 12:00'])
        ->and($report->of(false, false, $everywhere, $window, $at))->toBe([])
        ->and($report->of(true, true, $everywhere, new Plan(new PlacementClosed(World::placement(World::AWAY_PLACEMENT), $da)), $at))->toBe([]);
});

it('expects the shared variant and the home placement at the versions the caller saw', function (): void {
    $publish = new PublishEntry(World::entry(), new AggregateVersion(6), new RevisionNumber(4), World::placement(), new AggregateVersion(2), new Locale('da'));
    $unpublish = new UnpublishEntry(World::entry(), new AggregateVersion(7));
    $variant = new VariantRef(World::entry(), VariantKey::shared());

    expect($publish->expectedVersions()->reads)->toEqual([ReadVersion::at(World::placement(), new AggregateVersion(2)), ReadVersion::at($variant, new AggregateVersion(6))])
        ->and($publish->variant())->toEqual($variant)
        ->and($unpublish->expectedVersions()->reads)->toEqual([ReadVersion::at($variant, new AggregateVersion(7))])
        ->and($unpublish->variant())->toEqual($variant);
});

it('reads an entry read as absent as absent, with no placement in the locale', function (): void {
    $publish = new PublishEntryAggregates(World::entry(), null, null, World::placement(), null, null, new Locale('da'), [], World::at(0));
    $unpublish = new UnpublishEntryAggregates(World::entry(), null, [], World::at(0));
    $variant = new VariantRef(World::entry(), VariantKey::shared());
    $slot = new CanonicalPlacementRef(World::entry(), new Locale('da'));

    expect($publish->inLocale()->states)->toBe([])
        ->and($publish->versions()->of($variant))->toEqual(ReadVersion::absent($variant))
        ->and($publish->versions()->of($slot))->toEqual(ReadVersion::absent($slot))
        ->and($publish->versions()->of(World::placement()))->toEqual(ReadVersion::absent(World::placement()))
        ->and($unpublish->versions()->reads)->toEqual([ReadVersion::absent(World::entry()), ReadVersion::absent($variant)]);
});

it('refuses to plan from reads the kernel rules out first', function (): void {
    $clock = new FakeClock(new DateTimeImmutable(World::NOW));
    $publish = new PublishEntryAction(new FakeEntryReader, new FakePlacementReader, new FakeTypeCatalog, $clock);
    $unpublish = new UnpublishEntryAction(new FakeEntryReader, new FakePlacementReader, $clock);
    $command = new PublishEntry(World::entry(), new AggregateVersion(1), null, World::placement(), new AggregateVersion(1), new Locale('da'));

    expect(static fn (): Plan => $publish->plan($command, $publish->resolve($command)))->toThrow(LogicException::class, 'the kernel refuses such a call first')
        ->and(static fn (): Plan => $unpublish->plan(new UnpublishEntry(World::entry(), new AggregateVersion(1)), $unpublish->resolve(new UnpublishEntry(World::entry(), new AggregateVersion(1)))))
        ->toThrow(LogicException::class, 'which was read as absent');
});

it('tells that a variant has no released revision any more, and which it had', function (): void {
    $variant = new VariantRef(World::entry(), VariantKey::shared());
    $event = new VariantUnreleasedEvent(7, new VariantUnreleasedV1(World::entry(), $variant, 4));
    $data = $event->payload()->data();

    expect(VariantUnreleasedEvent::type())->toEqual(new EventType('variant.unreleased', 1))
        ->and([$event->aggregate()->type->value, $event->aggregate()->id->value, $event->aggregate()->version])->toBe(['variant', World::ENTRY.':shared', 7])
        ->and(array_keys($data->fields()))->toBe(['entry', 'revision', 'variant'])
        ->and($data->get('revision')->asInteger())->toBe(4)
        ->and($data->get('entry')->asIdentifier()->value)->toBe(World::ENTRY);
});

it('reads the shared variant of an entry without a head as absent, and authorizes on what it read', function (): void {
    $da = new Locale('da');
    $entry = new StoredEntry(World::entry(), EntryActionWorld::type(), World::node(World::HOME), new AggregateVersion(3), null);
    $placement = new StoredPlacement(World::placement(), World::entry(), World::node(World::AWAY), new AggregateVersion(2), []);
    $both = new PublishEntryAggregates(World::entry(), $entry, null, World::placement(), new AggregateVersion(2), $placement, $da, [], World::at(0));
    $entryOnly = new PublishEntryAggregates(World::entry(), $entry, null, World::placement(), null, null, $da, [], World::at(0));
    $placementOnly = new PublishEntryAggregates(World::entry(), null, null, World::placement(), new AggregateVersion(2), $placement, $da, [], World::at(0));
    $variant = new VariantRef(World::entry(), VariantKey::shared());

    expect($both->versions()->of($variant))->toEqual(ReadVersion::absent($variant))
        ->and($both->versions()->of(World::entry()))->toEqual(ReadVersion::at(World::entry(), new AggregateVersion(3)))
        ->and($both->authorizationScope())->toEqual(AuthorizationScope::on(new AuthorizationTarget(World::node(World::HOME), $da), new AuthorizationTarget(World::node(World::AWAY), $da)))
        ->and($entryOnly->authorizationScope())->toEqual(AuthorizationScope::on(new AuthorizationTarget(World::node(World::HOME), $da)))
        ->and($placementOnly->authorizationScope())->toEqual(AuthorizationScope::on(new AuthorizationTarget(World::node(World::AWAY), $da)))
        ->and(new PublishEntryAggregates(World::entry(), null, null, World::placement(), null, null, $da, [], World::at(0))->authorizationScope()->isAnywhere())->toBeTrue();
});

it('authorizes a release on the home in every locale and the window in the command\'s locale (security review S-1)', function (): void {
    $da = new Locale('da');
    $head = static fn (?int $released): StoredHead => new StoredHead(new AggregateVersion(6), new RevisionNumber(4), new RevisionNumber(4), $released === null ? null : new RevisionNumber($released));
    $entry = static fn (?int $released): StoredEntry => new StoredEntry(World::entry(), EntryActionWorld::type(), World::node(World::HOME), new AggregateVersion(3), $head($released));
    $placement = new StoredPlacement(World::placement(), World::entry(), World::node(World::HOME), new AggregateVersion(2), []);
    $aggregates = static fn (?int $released, ?int $revision): PublishEntryAggregates => new PublishEntryAggregates(
        World::entry(),
        $entry($released),
        NoteType::definition(),
        World::placement(),
        new AggregateVersion(2),
        $placement,
        $da,
        [],
        World::at(0),
        $revision === null ? null : new RevisionNumber($revision),
    );
    $window = AuthorizationScope::on(new AuthorizationTarget(World::node(World::HOME), $da), new AuthorizationTarget(World::node(World::HOME), $da));

    expect($aggregates(null, 4)->releases())->toBeTrue()
        ->and($aggregates(null, 4)->authorizationScope())->toEqual(AuthorizationScope::on(
            new AuthorizationTarget(World::node(World::HOME), $da),
            new AuthorizationTarget(World::node(World::HOME)),
            new AuthorizationTarget(World::node(World::HOME), $da),
        ))
        ->and($aggregates(3, 4)->releases())->toBeTrue()
        ->and($aggregates(4, 4)->releases())->toBeFalse()
        ->and($aggregates(4, 4)->authorizationScope())->toEqual($window)
        ->and($aggregates(null, null)->releases())->toBeFalse()
        ->and($aggregates(null, null)->authorizationScope())->toEqual($window);
});

it('refuses to publish an entry whose type this installation does not have', function (): void {
    $da = new Locale('da');
    $action = new PublishEntryAction(new FakeEntryReader, new FakePlacementReader, new FakeTypeCatalog, new FakeClock(new DateTimeImmutable(World::NOW)));
    $command = new PublishEntry(World::entry(), new AggregateVersion(1), new RevisionNumber(1), World::placement(), new AggregateVersion(1), $da);
    $entry = new StoredEntry(World::entry(), EntryActionWorld::type(), World::node(World::HOME), new AggregateVersion(1), null);
    $placement = new StoredPlacement(World::placement(), World::entry(), World::node(World::HOME), new AggregateVersion(1), []);

    $refusals = $action->refusals($command, new PublishEntryAggregates(World::entry(), $entry, null, World::placement(), new AggregateVersion(1), $placement, $da, [], World::at(0)));

    expect(array_map(static fn (CatalogError $error): array => [$error->code, $error->path?->toString(), $error->message], $refusals))
        ->toBe([[ErrorCode::ValidationFailed, 'entry', sprintf('The entry %s has no type of this installation.', World::ENTRY)]]);
});
