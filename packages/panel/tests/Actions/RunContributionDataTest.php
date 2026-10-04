<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Tests\Actions;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Identity\ClassificationAccess;
use Cbox\Cms\Contracts\PanelPoints\PageName;
use Cbox\Cms\Contracts\PanelPoints\PointId;
use Cbox\Cms\Contracts\PanelPoints\PointName;
use Cbox\Cms\Contracts\Pipeline\Query;
use Cbox\Cms\Contracts\Results\ReadContent;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Core\Reads\Domain\UnknownQuery;
use Cbox\Cms\Core\Registry\Domain\Dto\PanelFill;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Reads\Probe\ProbeCards;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Cbox\Cms\Core\Tests\Reads\QueryWorld;
use Cbox\Cms\Panel\Contributions\Actions\RunContributionData;
use Cbox\Cms\Panel\Contributions\Domain\ContributionTelemetry;
use Cbox\Cms\Panel\Contributions\Domain\DataOutcome;
use Cbox\Cms\Panel\Contributions\Domain\DataRefusal;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ActiveFill;
use Cbox\Cms\Panel\Contributions\Domain\Dto\ContributionDataCall;
use Cbox\Cms\Panel\Tests\Contributions\ContributionWorld;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Desk\DeskCardsV1;
use Cbox\Cms\Panel\Tests\Contributions\Fixtures\Tally\TallyNotes;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use LogicException;

/*
 * RunContributionData (PRD 13.4) called directly with the query pipeline of the core's QueryWorld
 * (the test-only read probe.read over fakes, its reader granted sensitive access) and a fake
 * telemetry (GUARDRAILS 9): it reads as the viewer's credential at the ceiling of the fill's
 * access, gives nothing for a read the pipeline rejects, such as one over the actor's budget, or
 * that throws, and records each outcome per addon.
 */

/**
 * The fill of tally.count, handed its props at the access given.
 */
function countFill(ClassificationAccess $access): ActiveFill
{
    $fill = ContributionWorld::registry()->panelPoint(new PointId(new PointName('desk.cards'), 1))?->fills[1] ?? throw new LogicException('No fill.');

    expect($fill)->toBeInstanceOf(PanelFill::class)
        ->and($fill->contribution->value)->toBe(ContributionWorld::COUNT);

    return new ActiveFill($fill, new PointId(new PointName('desk.cards'), 1), new DeskCardsV1('Weekly desk', 'Call the printer'), $access, $fill->query);
}

function dataAction(QueryWorld $world, FakeTelemetry $telemetry): RunContributionData
{
    return new RunContributionData($world->pipeline(), new FakeStopwatch, new ContributionTelemetry($telemetry));
}

function dataCall(QueryWorld $world, Query $query, ClassificationAccess $access = ClassificationAccess::Internal): ContributionDataCall
{
    return new ContributionDataCall(new PageName(ContributionWorld::PAGE), countFill($access), $query, $world->credential);
}

it('reads as the viewer at the ceiling of the fill s access and gives the result with that access', function (): void {
    $world = new QueryWorld;
    $world->access->grant($world->reader, [], ClassificationAccess::Sensitive);
    $telemetry = new FakeTelemetry;

    $data = dataAction($world, $telemetry)->run(dataCall($world, new ReadProbe));

    expect($data->outcome)->toBe(DataOutcome::Answered)
        ->and($data->access)->toBe(ClassificationAccess::Internal)
        ->and($data->result)->toBeInstanceOf(ProbeCards::class)
        ->and($data->result instanceof ProbeCards ? array_map(static fn (ReadContent $card): array => array_map(static fn (FieldHandle $handle): string => $handle->value, $card->fields->own->handles()), $data->result->contents()) : null)->toBe([['label', 'note']])
        ->and($world->audit->records)->toBe([])
        ->and($telemetry->counters())->toBe([]);

    $histogram = $telemetry->histograms()[0] ?? null;

    expect($histogram)->toBeInstanceOf(HistogramRecord::class)
        ->and($histogram?->name->value)->toBe(ContributionTelemetry::DATA_DURATION)
        ->and($histogram?->attributes->get(ContributionTelemetry::ADDON))->toBe('tally')
        ->and($histogram?->attributes->get(ContributionTelemetry::CONTRIBUTION))->toBe(ContributionWorld::COUNT)
        ->and($histogram?->attributes->get(ContributionTelemetry::POINT))->toBe('desk.cards@1')
        ->and($histogram?->attributes->get(ContributionTelemetry::PAGE))->toBe(ContributionWorld::PAGE)
        ->and($histogram?->attributes->get(ContributionTelemetry::QUERY))->toBe('tally.notes')
        ->and($histogram?->attributes->get(ContributionTelemetry::OUTCOME))->toBe('answered');
});

it('gives nothing for a read over the actor s budget and counts it with its code', function (): void {
    $world = new QueryWorld;
    $telemetry = new FakeTelemetry;

    $data = dataAction($world, $telemetry)->run(dataCall($world, new ReadProbe(QueryWorld::ACTOR_BUDGET + 1)));
    $counter = $telemetry->counters()[0] ?? null;

    expect($data->outcome)->toBe(DataOutcome::Rejected)
        ->and($data->result)->toBeNull()
        ->and($data->code)->toBe(ErrorCode::QueryOverBudget)
        ->and($counter)->toBeInstanceOf(CounterRecord::class)
        ->and($counter?->name->value)->toBe(ContributionTelemetry::DATA_ERRORS)
        ->and($counter?->attributes->get(ContributionTelemetry::ERROR_CODE))->toBe('query_over_budget')
        ->and($counter?->attributes->get(ContributionTelemetry::OUTCOME))->toBe('rejected');
});

it('gives nothing for a read that throws, and hands the exception on for the surface to report', function (): void {
    $world = new QueryWorld;
    $telemetry = new FakeTelemetry;

    $data = dataAction($world, $telemetry)->run(dataCall($world, new TallyNotes('a note')));

    expect($data->outcome)->toBe(DataOutcome::Failed)
        ->and($data->failure)->toBeInstanceOf(UnknownQuery::class)
        ->and($telemetry->counted(ContributionTelemetry::DATA_ERRORS))->toBe(1)
        ->and(($telemetry->counters()[0] ?? null)?->attributes->get(ContributionTelemetry::OUTCOME))->toBe('failed');
});

it('records a query the panel did not run, with its reason', function (): void {
    $world = new QueryWorld;
    $telemetry = new FakeTelemetry;

    $data = dataAction($world, $telemetry)->refused(new PageName(ContributionWorld::PAGE), countFill(ClassificationAccess::Internal), DataRefusal::InputInvalid);

    expect($data->outcome)->toBe(DataOutcome::Refused)
        ->and($data->refusal)->toBe(DataRefusal::InputInvalid)
        ->and(($telemetry->counters()[0] ?? null)?->attributes->get(ContributionTelemetry::REASON))->toBe('input_invalid')
        ->and($world->transaction->commits + $world->transaction->rollBacks)->toBe(0);
});
