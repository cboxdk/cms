<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Results;

use Cbox\Cms\Contracts\Content\Locale;
use Cbox\Cms\Contracts\Ids\PlacementId;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersion;
use Cbox\Cms\Contracts\Pipeline\ReadVersions;
use Cbox\Cms\Contracts\Plans\Mutations\PlacementClosed;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\BecomesVisible;
use Cbox\Cms\Contracts\Results\DryRunReport;
use DateTimeImmutable;

/*
 * The placements a dry run shows as becoming visible (PRD 6.4): each holds its placement, its
 * locale and the time it becomes visible in UTC, and the report sorts them by placement and
 * locale, whatever order the action gives them in. A report without them lists none. A
 * PlacementClosed changes the placement it names.
 */

function visibleUuid(int $n): string
{
    return sprintf('01936f5e-8a2b-7c3d-9e4f-%012d', $n);
}

it('holds the time a placement becomes visible in UTC', function (): void {
    $visible = new BecomesVisible(PlacementId::fromString(visibleUuid(1)), new Locale('da'), new DateTimeImmutable('2026-03-10T14:00:00+02:00'));

    expect($visible->from->format(DATE_ATOM))->toBe('2026-03-10T12:00:00+00:00')
        ->and($visible->locale->value)->toBe('da');
});

it('lists the placements that become visible in the order of placement and locale, and none by default', function (): void {
    $at = new DateTimeImmutable('2026-03-10T12:00:00Z');
    $second = new BecomesVisible(PlacementId::fromString(visibleUuid(2)), new Locale('da'), $at);
    $firstEn = new BecomesVisible(PlacementId::fromString(visibleUuid(1)), new Locale('en'), $at);
    $firstDa = new BecomesVisible(PlacementId::fromString(visibleUuid(1)), new Locale('da'), $at);

    expect(DryRunReport::of(new Plan, new ReadVersions, [$second, $firstEn, $firstDa])->visible)->toBe([$firstDa, $firstEn, $second])
        ->and(DryRunReport::of(new Plan, new ReadVersions)->visible)->toBe([]);
});

it('closes the placement it names', function (): void {
    $placement = PlacementId::fromString(visibleUuid(3));
    $closed = new PlacementClosed($placement, new Locale('da'));

    expect($closed->aggregate()->aggregateKey())->toBe('placement:'.visibleUuid(3))
        ->and(DryRunReport::of(new Plan($closed), new ReadVersions(ReadVersion::at($placement, new AggregateVersion(2))))->diff[0]->after->value)->toBe(3);
});
