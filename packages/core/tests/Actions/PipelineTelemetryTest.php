<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Actions;

use Cbox\Cms\Contracts\Attributes\Phase;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Contracts\Hooks\HookDecision;
use Cbox\Cms\Contracts\Ids\ActorId;
use Cbox\Cms\Contracts\Ids\EntryId;
use Cbox\Cms\Contracts\Plans\Mutations\ActorDeactivated;
use Cbox\Cms\Contracts\Plans\Plan;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\MetricUnit;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\SpanStatus;
use Cbox\Cms\Core\Pipeline\Domain\InvalidCommandCall;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\PipelineWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\Hooks\CallbackAuthorize;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeType;
use Cbox\Cms\Core\Tests\Reads\Probe\ReadProbe;
use Cbox\Cms\Core\Tests\Reads\QueryWorld;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

/*
 * The telemetry of the command and query pipelines (GUARDRAILS 5, PRD 6.5 invariant 10), called
 * directly with the test-only command probe.rename and query probe.read and the fakes of their
 * ports, the telemetry included (GUARDRAILS 9). Every call gets exactly one span named after its
 * action, with its outcome and, for a committed command, its changeset, and the metrics for its
 * duration and its errors, without any instrumentation in the actions. No attribute of any span or
 * metric ever holds a field value, so none above public.
 */

/**
 * @return array<string, string|int|float|bool>
 */
function telemetryAttributes(Attributes $attributes): array
{
    $values = [];

    foreach ($attributes->attributes as $attribute) {
        $values[$attribute->name->value] = $attribute->value;
    }

    return $values;
}

/**
 * Every attribute value of every record the fake took, as text.
 *
 * @return list<string>
 */
function telemetryValues(FakeTelemetry $telemetry): array
{
    $values = [];
    $sets = [
        ...array_map(static fn (SpanRecord $span): Attributes => $span->attributes, $telemetry->spans()),
        ...array_map(static fn (CounterRecord $counter): Attributes => $counter->attributes, $telemetry->counters()),
        ...array_map(static fn (HistogramRecord $histogram): Attributes => $histogram->attributes, $telemetry->histograms()),
    ];

    foreach ($sets as $attributes) {
        foreach ($attributes->attributes as $attribute) {
            $values[] = is_bool($attribute->value) ? ($attribute->value ? 'true' : 'false') : (string) $attribute->value;
        }
    }

    return $values;
}

/**
 * Every text value of the fields, own and extension, in the order held.
 *
 * @return list<string>
 */
function telemetryFieldTexts(FieldValues $fields): array
{
    $maps = [$fields->own, ...array_map(static fn (ExtensionFields $extension): FieldMap => $extension->fields, $fields->extensions)];
    $texts = [];

    foreach ($maps as $map) {
        foreach ($map->fields as $named) {
            if ($named->value instanceof TextValue) {
                $texts[] = $named->value->value;
            }
        }
    }

    return $texts;
}

function telemetryLeaks(FakeTelemetry $telemetry, string $text): bool
{
    return array_any(telemetryValues($telemetry), static fn (string $value): bool => str_contains($value, $text));
}

it('gives a committed command one span with its action, outcome, changeset and correlation id, and its duration', function (): void {
    $world = new PipelineWorld;
    $world->hook(new CallbackAuthorize(static function () use ($world): HookDecision {
        $world->stopwatch->advance(7_000_000);

        return HookDecision::noObjection();
    }), Phase::Authorize);

    $result = $world->run($world->command());
    $spans = $world->telemetry->spans();

    expect($spans)->toHaveCount(1)
        ->and($spans[0]->name->value)->toBe('probe.rename')
        ->and($spans[0]->status)->toBe(SpanStatus::Ok)
        ->and($spans[0]->start)->toEqual($world->clock->now())
        ->and($spans[0]->durationNanoseconds)->toBe(7_000_000)
        ->and(telemetryAttributes($spans[0]->attributes))->toBe([
            PipelineTelemetry::ACTION => 'probe.rename',
            PipelineTelemetry::KIND => 'command',
            PipelineTelemetry::VERSION => 1,
            PipelineTelemetry::CHANGESET => $result->receipt->changesetId?->toString(),
            PipelineTelemetry::COMMIT_POSITION => $result->receipt->position?->value,
            PipelineTelemetry::CORRELATION => 'probe-correlation',
            PipelineTelemetry::ISSUER => 'human',
            PipelineTelemetry::OUTCOME => 'committed',
            PipelineTelemetry::SURFACE => 'rest',
            PipelineTelemetry::WAIT_LEVEL => $world->call($world->command())->envelope->waitLevel->value,
        ])
        ->and($world->telemetry->recorded(PipelineTelemetry::COMMAND_DURATION))->toBe([7.0])
        ->and($world->telemetry->histograms()[0]->unit)->toBe(MetricUnit::Milliseconds)
        ->and(telemetryAttributes($world->telemetry->histograms()[0]->attributes))->toBe([
            PipelineTelemetry::ACTION => 'probe.rename',
            PipelineTelemetry::KIND => 'command',
            PipelineTelemetry::VERSION => 1,
            PipelineTelemetry::OUTCOME => 'committed',
        ])
        ->and($world->telemetry->counters())->toBe([]);
});

it('gives each command a span of its own with its own changeset', function (): void {
    $world = new PipelineWorld;
    $world->committing();

    // Three entries, so each command creates its own and none reads what another committed.
    $results = array_map(
        static fn (string $entry): WriteResult => $world->run($world->command(entry: EntryId::fromString($entry))),
        ['01936f5e-8a2b-7c3d-9e4f-0000000000f1', '01936f5e-8a2b-7c3d-9e4f-0000000000f2', '01936f5e-8a2b-7c3d-9e4f-0000000000f3'],
    );
    $spans = $world->telemetry->spans();

    expect($spans)->toHaveCount(3)
        ->and(array_map(static fn (SpanRecord $span): string|int|float|bool|null => $span->attributes->get(PipelineTelemetry::CHANGESET), $spans))
        ->toBe(array_map(static fn (WriteResult $result): ?string => $result->receipt->changesetId?->toString(), $results))
        ->and(array_unique(array_map(static fn (SpanRecord $span): string|int|float|bool|null => $span->attributes->get(PipelineTelemetry::CHANGESET), $spans)))->toHaveCount(3)
        ->and($world->telemetry->recorded(PipelineTelemetry::COMMAND_DURATION))->toHaveCount(3);
});

it('marks a rejected command\'s span as an error with its first error code, and counts the error', function (): void {
    $world = new PipelineWorld;
    $world->refuse('The actor has no grant on the home node.');

    $world->run($world->command());
    $span = $world->telemetry->spans()[0];

    expect($world->telemetry->spans())->toHaveCount(1)
        ->and($span->status)->toBe(SpanStatus::Error)
        ->and($span->attributes->get(PipelineTelemetry::OUTCOME))->toBe('rejected')
        ->and($span->attributes->get(PipelineTelemetry::ERROR_CODE))->toBe('unauthorized')
        ->and($span->attributes->get(PipelineTelemetry::ERROR_COUNT))->toBe(1)
        ->and($span->attributes->get(PipelineTelemetry::CHANGESET))->toBeNull()
        ->and($world->telemetry->counted(PipelineTelemetry::COMMAND_ERRORS))->toBe(1)
        ->and(telemetryAttributes($world->telemetry->counters()[0]->attributes))->toBe([
            PipelineTelemetry::ACTION => 'probe.rename',
            PipelineTelemetry::KIND => 'command',
            PipelineTelemetry::VERSION => 1,
            PipelineTelemetry::ERROR_CODE => 'unauthorized',
            PipelineTelemetry::OUTCOME => 'rejected',
        ])
        ->and($world->telemetry->recorded(PipelineTelemetry::COMMAND_DURATION))->toHaveCount(1);
});

it('gives a dry run an ok span without a changeset', function (): void {
    $world = new PipelineWorld;

    $world->run($world->command(), true);
    $span = $world->telemetry->spans()[0];

    expect($span->status)->toBe(SpanStatus::Ok)
        ->and($span->attributes->get(PipelineTelemetry::OUTCOME))->toBe('dry_run')
        ->and($span->attributes->get(PipelineTelemetry::CHANGESET))->toBeNull()
        ->and($world->telemetry->counters())->toBe([]);
});

it('exports the span of a command that throws, with the exception\'s class, and throws on', function (): void {
    $world = new PipelineWorld;
    $world->unreadPlan = new Plan(new ActorDeactivated(ActorId::fromString('01936f5e-8a2b-7c3d-9e4f-0000000000f8')));

    expect(fn (): WriteResult => $world->run($world->command()))->toThrow(InvalidCommandCall::class);

    $span = $world->telemetry->spans()[0];

    expect($world->telemetry->spans())->toHaveCount(1)
        ->and($span->status)->toBe(SpanStatus::Error)
        ->and($span->attributes->get(PipelineTelemetry::OUTCOME))->toBe(PipelineTelemetry::THREW)
        ->and($span->attributes->get(PipelineTelemetry::EXCEPTION))->toBe(InvalidCommandCall::class)
        ->and($span->attributes->get(PipelineTelemetry::CORRELATION))->toBe('probe-correlation')
        ->and($world->telemetry->counted(PipelineTelemetry::COMMAND_ERRORS))->toBe(1)
        ->and($world->telemetry->counters()[0]->attributes->get(PipelineTelemetry::EXCEPTION))->toBe(InvalidCommandCall::class);
});

it('puts no field value of a command in any attribute, committed or rejected', function (): void {
    $world = new PipelineWorld;
    $world->committing();
    $fields = new FieldValues(
        new FieldMap(
            new NamedValue(new FieldHandle('label'), new TextValue('Public label 4711')),
            new NamedValue(new FieldHandle('memo'), new TextValue('Confidential memo 4711')),
        ),
        new ExtensionFields(new FieldNamespace(ProbeType::EXTENDER), new FieldMap(new NamedValue(new FieldHandle('code'), new TextValue('Confidential code 4711')))),
    );
    $invalid = new FieldValues(new FieldMap(
        new NamedValue(new FieldHandle('colour'), new TextValue('Undeclared colour 4712')),
        new NamedValue(new FieldHandle('memo'), new TextValue('Confidential memo 4712')),
    ));

    $committed = $world->run($world->command($fields));
    $rejected = $world->run($world->command($invalid));

    expect($committed->receipt->changesetId)->not->toBeNull()
        ->and($rejected->errors)->not->toBe([])
        ->and($world->telemetry->spans())->toHaveCount(2)
        ->and(telemetryValues($world->telemetry))->not->toBe([]);

    foreach ([...telemetryFieldTexts($fields), ...telemetryFieldTexts($invalid)] as $text) {
        expect(telemetryLeaks($world->telemetry, $text))->toBeFalse(sprintf('The field value "%s" reached an attribute.', $text));
    }
});

it('gives an answered query one span with its action, outcome, position and classification access', function (): void {
    $world = new QueryWorld;

    $result = $world->pipeline()->run(new QueryCall(new ReadProbe(2), $world->credential, Surface::Rest, new CorrelationId('read-correlation')));
    $spans = $world->telemetry->spans();

    expect($result->isAnswered())->toBeTrue()
        ->and($spans)->toHaveCount(1)
        ->and($spans[0]->name->value)->toBe('probe.read')
        ->and($spans[0]->status)->toBe(SpanStatus::Ok)
        ->and($spans[0]->start)->toEqual($world->clock->now())
        ->and(telemetryAttributes($spans[0]->attributes))->toBe([
            PipelineTelemetry::ACTION => 'probe.read',
            PipelineTelemetry::KIND => 'query',
            PipelineTelemetry::VERSION => 2,
            PipelineTelemetry::CLASSIFICATION => 'confidential',
            PipelineTelemetry::CONTENT_KEYS => count($result->contentKeys),
            PipelineTelemetry::CORRELATION => 'read-correlation',
            PipelineTelemetry::OUTCOME => 'answered',
            PipelineTelemetry::READ_POSITION => QueryWorld::POSITION,
            PipelineTelemetry::SURFACE => 'rest',
        ])
        ->and($world->telemetry->recorded(PipelineTelemetry::QUERY_DURATION))->toBe([0.0])
        ->and($world->telemetry->counters())->toBe([]);
});

it('gives each query a span of its own, and a rejected one an error span and an error count', function (): void {
    $world = new QueryWorld;
    $world->read();
    $world->refuse('The reader may not read the library.');

    $rejected = $world->read();
    $spans = $world->telemetry->spans();

    expect($rejected->isAnswered())->toBeFalse()
        ->and($spans)->toHaveCount(2)
        ->and($spans[1]->status)->toBe(SpanStatus::Error)
        ->and($spans[1]->attributes->get(PipelineTelemetry::OUTCOME))->toBe('rejected')
        ->and($spans[1]->attributes->get(PipelineTelemetry::ERROR_CODE))->toBe('unauthorized')
        ->and($spans[1]->attributes->get(PipelineTelemetry::READ_POSITION))->toBeNull()
        ->and($spans[1]->attributes->get(PipelineTelemetry::CORRELATION))->toBeNull()
        ->and($world->telemetry->counted(PipelineTelemetry::QUERY_ERRORS))->toBe(1)
        ->and($world->telemetry->recorded(PipelineTelemetry::QUERY_DURATION))->toHaveCount(2);
});

it('puts no field value a query read in any attribute, for an actor or the anonymous principal', function (): void {
    $world = new QueryWorld;

    $read = $world->read(3);
    $anonymous = $world->read(1, true);

    expect($read->isAnswered())->toBeTrue()
        ->and($anonymous->isAnswered())->toBeTrue()
        ->and($world->telemetry->spans())->toHaveCount(2);

    foreach (QueryWorld::ENTRIES as $entry) {
        foreach (telemetryFieldTexts(QueryWorld::card($entry)->fields) as $text) {
            expect(telemetryLeaks($world->telemetry, $text))->toBeFalse(sprintf('The field value "%s" reached an attribute.', $text));
        }
    }
});
