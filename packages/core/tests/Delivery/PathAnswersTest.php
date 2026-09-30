<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Delivery;

use Cbox\Cms\Contracts\Cache\DependencyKey;
use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Content\Slug;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Ids\NodeId;
use Cbox\Cms\Core\Delivery\Domain\PathAnswers;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use Cbox\Cms\Core\Routing\Domain\Dto\PathExplanation;
use Cbox\Cms\Core\Routing\Domain\Dto\PlacementStep;
use Cbox\Cms\Core\Routing\Domain\Dto\RouteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\SiteStep;
use Cbox\Cms\Core\Routing\Domain\Dto\VisibilityStep;
use Cbox\Cms\Core\Routing\Domain\EntryLifecycle;
use Cbox\Cms\Core\Routing\Domain\Host;
use Cbox\Cms\Core\Routing\Domain\RequestPath;
use Cbox\Cms\Core\Routing\Domain\ResolveOutcome;
use Cbox\Cms\Core\Routing\Domain\VisibilityDecision;
use DateTimeImmutable;

/*
 * What the delivery API answers for a resolution (PRD 6.6, 8.10): the code by outcome and visibility
 * decision, the detail, the content keys with the node the slug was looked up under, the valid_until
 * of the window and whether the edge may serve the answer stale.
 */

const ANSWERED_NODE = '01936f5e-8a2b-7c3d-9e4f-000000003612';

const ANSWERED_ENTRY = '01936f5e-8a2b-7c3d-9e4f-000000003631';

function answeredExplanation(ResolveOutcome $outcome, ?VisibilityStep $visibility = null, bool $placed = true): PathExplanation
{
    return new PathExplanation(
        $outcome,
        new SiteStep(new Host('north.example'), new Locale('da'), null, null, true),
        new RouteStep(new RequestPath('/nyheder/harbour'), '/nyheder', 'harbour'),
        placement: $placed ? new PlacementStep(NodeId::fromString(ANSWERED_NODE), new Slug('harbour')) : null,
        visibility: $visibility,
    );
}

function answeredVisibility(VisibilityDecision $decision, ?EntryLifecycle $lifecycle = EntryLifecycle::Active, ?DateTimeImmutable $validUntil = null): VisibilityStep
{
    return new VisibilityStep($decision, new DateTimeImmutable('2026-03-10T12:00:00Z'), $lifecycle, null, Visibility::Live, new TimeWindow, $validUntil);
}

/**
 * @param  list<DependencyKey>  $read
 * @return list<string>
 */
function answeredKeys(array $read, bool $placed): array
{
    return array_map(static fn (DependencyKey $key): string => $key->toString(), PathAnswers::contentKeys(answeredExplanation(ResolveOutcome::NoPlacement, placed: $placed), $read));
}

it('answers a resolved path with no code, and an unknown host with host_not_configured', function (): void {
    expect(PathAnswers::code(answeredExplanation(ResolveOutcome::Resolved)))->toBeNull()
        ->and(PathAnswers::code(answeredExplanation(ResolveOutcome::UnknownHost)))->toBe(ErrorCode::HostNotConfigured);
});

it('answers every outcome where nothing is shown now with path_not_found', function (ResolveOutcome $outcome): void {
    expect(PathAnswers::code(answeredExplanation($outcome)))->toBe(ErrorCode::PathNotFound);
})->with([ResolveOutcome::UnknownSite, ResolveOutcome::LocaleNotPublished, ResolveOutcome::NoRoute, ResolveOutcome::NoSlug, ResolveOutcome::NoPlacement, ResolveOutcome::NotRoutable]);

it('answers what was withdrawn, tombstoned or purged with path_gone, and every other decision with path_not_found', function (VisibilityDecision $decision, ?EntryLifecycle $lifecycle, ErrorCode $code): void {
    expect(PathAnswers::code(answeredExplanation(ResolveOutcome::NotVisible, answeredVisibility($decision, $lifecycle))))->toBe($code);
})->with([
    'a withdrawn variant' => [VisibilityDecision::VariantWithdrawn, EntryLifecycle::Active, ErrorCode::PathGone],
    'a withdrawn placement' => [VisibilityDecision::PlacementWithdrawn, EntryLifecycle::Active, ErrorCode::PathGone],
    'a tombstoned entry' => [VisibilityDecision::EntryNotActive, EntryLifecycle::Tombstoned, ErrorCode::PathGone],
    'a purged entry' => [VisibilityDecision::EntryNotActive, EntryLifecycle::Purged, ErrorCode::PathGone],
    'an archived entry' => [VisibilityDecision::EntryNotActive, EntryLifecycle::Archived, ErrorCode::PathNotFound],
    'a merged entry' => [VisibilityDecision::EntryNotActive, EntryLifecycle::Merged, ErrorCode::PathNotFound],
    'an entry the reader cannot read' => [VisibilityDecision::EntryNotActive, null, ErrorCode::PathNotFound],
    'no released revision' => [VisibilityDecision::NoReleasedRevision, EntryLifecycle::Active, ErrorCode::PathNotFound],
    'a hidden placement' => [VisibilityDecision::PlacementHidden, EntryLifecycle::Active, ErrorCode::PathNotFound],
    'before the window' => [VisibilityDecision::BeforeWindow, EntryLifecycle::Active, ErrorCode::PathNotFound],
    'after the window' => [VisibilityDecision::AfterWindow, EntryLifecycle::Active, ErrorCode::PathNotFound],
]);

it('says the cause of each outcome in the detail', function (): void {
    $site = new SiteStep(new Host('north.example'), new Locale('da'), null, null, false);

    expect(PathAnswers::detail(answeredExplanation(ResolveOutcome::Resolved)))->toBe('/nyheder/harbour in da at north.example resolved.')
        ->and(PathAnswers::detail(answeredExplanation(ResolveOutcome::UnknownHost)))->toBe('No configured site is served at north.example.')
        ->and(PathAnswers::detail(new PathExplanation(ResolveOutcome::UnknownSite, $site)))->toBe('The site configured for north.example does not exist.')
        ->and(PathAnswers::detail(new PathExplanation(ResolveOutcome::LocaleNotPublished, $site)))->toBe('The site at north.example does not publish in da.')
        ->and(PathAnswers::detail(new PathExplanation(ResolveOutcome::NoRoute, $site)))->toBe('Nothing is placed at the path in da at north.example.')
        ->and(PathAnswers::detail(answeredExplanation(ResolveOutcome::NoSlug)))->toBe('Nothing is placed at /nyheder/harbour in da at north.example.')
        ->and(PathAnswers::detail(answeredExplanation(ResolveOutcome::NoPlacement)))->toBe('Nothing is placed at /nyheder/harbour in da at north.example.')
        ->and(PathAnswers::detail(answeredExplanation(ResolveOutcome::NotRoutable)))->toBe('What is placed at /nyheder/harbour in da at north.example has no URL.')
        ->and(PathAnswers::detail(answeredExplanation(ResolveOutcome::NotVisible, answeredVisibility(VisibilityDecision::AfterWindow))))->toBe('What is placed at /nyheder/harbour in da at north.example is not shown: after_window.')
        ->and(PathAnswers::detail(answeredExplanation(ResolveOutcome::NotVisible)))->toBe('What is placed at /nyheder/harbour in da at north.example is not shown: not visible.');
});

it('tags an answer with the read\'s keys and the node the slug was looked up under, once a placement was looked up', function (): void {
    $entry = DependencyKey::entry(EntryId::fromString(ANSWERED_ENTRY));
    $keys = answeredKeys(...);

    expect($keys([$entry], true))->toBe(['e-'.ANSWERED_ENTRY, 'n-'.ANSWERED_NODE])
        ->and($keys([], true))->toBe(['n-'.ANSWERED_NODE])
        ->and($keys([$entry], false))->toBe(['e-'.ANSWERED_ENTRY])
        ->and($keys([], false))->toBe([]);
});

it('holds an answer until the window next changes it, and lets only a resolved path whose window never ends be served stale', function (): void {
    $until = new DateTimeImmutable('2026-03-10T17:00:00Z');

    expect(PathAnswers::validUntil(answeredExplanation(ResolveOutcome::Resolved, answeredVisibility(VisibilityDecision::Visible, validUntil: $until))))->toEqual($until)
        ->and(PathAnswers::validUntil(answeredExplanation(ResolveOutcome::NoPlacement)))->toBeNull()
        ->and(PathAnswers::mayBeStale(answeredExplanation(ResolveOutcome::Resolved, answeredVisibility(VisibilityDecision::Visible))))->toBeTrue()
        ->and(PathAnswers::mayBeStale(answeredExplanation(ResolveOutcome::Resolved, answeredVisibility(VisibilityDecision::Visible, validUntil: $until))))->toBeFalse()
        ->and(PathAnswers::mayBeStale(answeredExplanation(ResolveOutcome::NotVisible, answeredVisibility(VisibilityDecision::AfterWindow))))->toBeFalse()
        ->and(PathAnswers::mayBeStale(answeredExplanation(ResolveOutcome::NoPlacement)))->toBeFalse();
});
