<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Telemetry\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Surface;
use Cbox\Cms\Contracts\Clock;
use Cbox\Cms\Contracts\Consistency\CommitPosition;
use Cbox\Cms\Contracts\Envelope\CorrelationId;
use Cbox\Cms\Contracts\Ids\ChangesetId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\QueryResult;
use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\MetricUnit;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\SpanStatus;
use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Core\Pipeline\Domain\Dto\ActionBinding;
use Cbox\Cms\Core\Pipeline\Domain\Dto\CommandCall;
use Cbox\Cms\Core\Pipeline\Domain\Stopwatch;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryBinding;
use Cbox\Cms\Core\Reads\Domain\Dto\QueryCall;
use Cbox\Cms\Core\Telemetry\Domain\Dto\CallMeasure;
use Closure;
use Throwable;

/**
 * The telemetry of the command and query pipelines (GUARDRAILS 5): each call of an action gets one
 * span and its metrics, exported through the Telemetry contract, so no action is instrumented by
 * hand. The pipeline runs the whole call, its transaction included, inside command() or query().
 *
 * The span is named after the action, such as `entry.create`, starts at the Clock's time and lasts
 * the call's real time on the Stopwatch. It is ok when the call was committed, answered or run dry,
 * and an error when it was rejected or threw, which it throws on after the export. Its attributes:
 *
 * - every call: `cms.action`, `cms.action.version`, `cms.action.kind` (`command` or `query`) and
 *   `cms.outcome`, the outcome of the result (`committed`, `committed_wait_timeout`, `dry_run`,
 *   `rejected`, `answered`) or `exception` for a call that threw;
 * - a command: `cms.correlation_id`, `cms.surface`, `cms.issuer_kind` and `cms.wait_level` from its
 *   envelope, and when it committed `cms.changeset_id` and `cms.commit_position`;
 * - a query: `cms.surface` and `cms.correlation_id` when its call has them, and when it was
 *   answered `cms.classification_access`, `cms.read_position` and `cms.content_keys`, their count;
 * - a rejected call: `cms.error.code`, the catalog code of its first error, and `cms.error.count`;
 * - a call that threw: `cms.exception.type`, the exception's class.
 *
 * The metrics are the histograms `cms.command.duration` and `cms.query.duration` in milliseconds,
 * for every call, and the counters `cms.command.errors` and `cms.query.errors`, for each call that
 * was rejected or threw, under `cms.action`, `cms.action.version`, `cms.action.kind` and
 * `cms.outcome`, with `cms.error.code` or `cms.exception.type` on an error: no id, so their
 * cardinality stays that of the actions and the error codes.
 *
 * Attributes hold ids, names, codes and counts only. No field value, error message or exception
 * message ever reaches one, so no value of a field classified above public does (invariant 10),
 * and no actor id or other personal data does (GUARDRAILS 5).
 */
#[Internal]
final readonly class PipelineTelemetry
{
    public const string COMMAND_DURATION = 'cms.command.duration';

    public const string COMMAND_ERRORS = 'cms.command.errors';

    public const string QUERY_DURATION = 'cms.query.duration';

    public const string QUERY_ERRORS = 'cms.query.errors';

    public const string ACTION = 'cms.action';

    public const string VERSION = 'cms.action.version';

    public const string KIND = 'cms.action.kind';

    public const string OUTCOME = 'cms.outcome';

    public const string CHANGESET = 'cms.changeset_id';

    public const string COMMIT_POSITION = 'cms.commit_position';

    public const string CORRELATION = 'cms.correlation_id';

    public const string SURFACE = 'cms.surface';

    public const string ISSUER = 'cms.issuer_kind';

    public const string WAIT_LEVEL = 'cms.wait_level';

    public const string CLASSIFICATION = 'cms.classification_access';

    public const string READ_POSITION = 'cms.read_position';

    public const string CONTENT_KEYS = 'cms.content_keys';

    public const string ERROR_CODE = 'cms.error.code';

    public const string ERROR_COUNT = 'cms.error.count';

    public const string EXCEPTION = 'cms.exception.type';

    /** The outcome of a call that threw. */
    public const string THREW = 'exception';

    public function __construct(
        private Telemetry $telemetry,
        private Clock $clock,
        private Stopwatch $stopwatch,
    ) {}

    /**
     * Runs a command's call and exports its span and metrics.
     *
     * @param  Closure(): WriteResult  $run
     *
     * @throws Throwable what the call throws, after its span is exported
     */
    public function command(ActionBinding $binding, CommandCall $call, Closure $run): WriteResult
    {
        $envelope = $call->envelope;
        $measure = new CallMeasure(ActionKind::Command, $binding->command, $binding->version, $this->clock->now(), $this->stopwatch->nanoseconds(), [
            Attribute::of(self::CORRELATION, $envelope->correlationId->value),
            Attribute::of(self::SURFACE, $envelope->surface->value),
            Attribute::of(self::ISSUER, $envelope->issuerKind->value),
            Attribute::of(self::WAIT_LEVEL, $envelope->waitLevel->value),
        ]);

        try {
            $result = $run();
        } catch (Throwable $thrown) {
            $this->threw($measure, $thrown);

            throw $thrown;
        }

        $receipt = $result->receipt;
        $details = [];

        if ($receipt->changesetId instanceof ChangesetId) {
            $details[] = Attribute::of(self::CHANGESET, $receipt->changesetId->toString());
        }

        if ($receipt->position instanceof CommitPosition) {
            $details[] = Attribute::of(self::COMMIT_POSITION, $receipt->position->value);
        }

        $this->ended($measure, $result->outcome()->value, $result->errors, $details);

        return $result;
    }

    /**
     * Runs a query's call and exports its span and metrics.
     *
     * @param  Closure(): QueryResult  $run
     *
     * @throws Throwable what the call throws, after its span is exported
     */
    public function query(QueryBinding $binding, QueryCall $call, Closure $run): QueryResult
    {
        $context = [];

        if ($call->surface instanceof Surface) {
            $context[] = Attribute::of(self::SURFACE, $call->surface->value);
        }

        if ($call->correlationId instanceof CorrelationId) {
            $context[] = Attribute::of(self::CORRELATION, $call->correlationId->value);
        }

        $measure = new CallMeasure(ActionKind::Query, $binding->query, $binding->version, $this->clock->now(), $this->stopwatch->nanoseconds(), $context);

        try {
            $result = $run();
        } catch (Throwable $thrown) {
            $this->threw($measure, $thrown);

            throw $thrown;
        }

        $details = [];

        if ($result->isAnswered()) {
            $details[] = Attribute::of(self::CLASSIFICATION, $result->access->value);
            $details[] = Attribute::of(self::CONTENT_KEYS, count($result->contentKeys));

            if ($result->position instanceof CommitPosition) {
                $details[] = Attribute::of(self::READ_POSITION, $result->position->value);
            }
        }

        $this->ended($measure, $result->isAnswered() ? 'answered' : 'rejected', $result->errors, $details);

        return $result;
    }

    /**
     * @param  list<CatalogError>  $errors
     * @param  list<Attribute>  $details
     */
    private function ended(CallMeasure $call, string $outcome, array $errors, array $details): void
    {
        $failure = [];

        if ($errors !== []) {
            $failure[] = Attribute::of(self::ERROR_CODE, $errors[0]->code->value);
        }

        $count = $errors === [] ? [] : [Attribute::of(self::ERROR_COUNT, count($errors))];

        $this->export($call, $outcome, $errors !== [], [...$details, ...$count], $failure);
    }

    private function threw(CallMeasure $call, Throwable $thrown): void
    {
        $type = $thrown::class;
        $named = strstr($type, "\0", true);

        $this->export($call, self::THREW, true, [], [Attribute::of(self::EXCEPTION, $named === false ? $type : $named)]);
    }

    /**
     * @param  list<Attribute>  $spanOnly  attributes of the span alone
     * @param  list<Attribute>  $failure  attributes of an error, on the span and the metrics
     */
    private function export(CallMeasure $call, string $outcome, bool $error, array $spanOnly, array $failure): void
    {
        $nanoseconds = max(0, $this->stopwatch->nanoseconds() - $call->from);
        $metric = [
            Attribute::of(self::ACTION, $call->name->value),
            Attribute::of(self::VERSION, $call->version),
            Attribute::of(self::KIND, $call->kind->value),
            Attribute::of(self::OUTCOME, $outcome),
            ...$failure,
        ];
        $metrics = new Attributes(...$metric);

        $this->telemetry->span(new SpanRecord(
            new TelemetryName($call->name->value),
            $call->start,
            $nanoseconds,
            $error ? SpanStatus::Error : SpanStatus::Ok,
            new Attributes(...$metric, ...$call->context, ...$spanOnly),
        ));
        $this->telemetry->histogram(new HistogramRecord(
            new TelemetryName($call->kind === ActionKind::Command ? self::COMMAND_DURATION : self::QUERY_DURATION),
            $nanoseconds / 1_000_000,
            MetricUnit::Milliseconds,
            $metrics,
        ));

        if ($error) {
            $this->telemetry->counter(new CounterRecord(
                new TelemetryName($call->kind === ActionKind::Command ? self::COMMAND_ERRORS : self::QUERY_ERRORS),
                1,
                $metrics,
            ));
        }
    }
}
