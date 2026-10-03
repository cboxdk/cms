---
title: Telemetry
weight: 41
description: "The Telemetry contract: spans, counters and histograms, the default LogTelemetry on Laravel's logging, what the command and query pipelines export for every action, the testkit's FakeTelemetry and the shared suite TelemetryContract with its harness."
---

# Telemetry

<!-- extension-point: Cbox\Cms\Contracts\Telemetry\Telemetry -->
<!-- extension-point: Cbox\Cms\Testkit\Telemetry\TelemetryHarness -->
<!-- extension-point: Cbox\Cms\Testkit\Telemetry\TelemetryContract -->

Telemetry export is a contract (GUARDRAILS 2.3). `Cbox\Cms\Contracts\Telemetry\Telemetry` takes the kernel's spans, counters and histograms, and the container binds it as a singleton to the class in `cbox-cms.contracts`. The default is `Cbox\Cms\Core\Telemetry\Adapter\LogTelemetry`, which writes each record as a structured entry on the application's log through Laravel's logging. An application that exports to OpenTelemetry binds its own implementation; the one on laravel-telemetry belongs to the app template.

## The contract

- `span(SpanRecord $span): void` exports one finished span.
- `counter(CounterRecord $counter): void` adds a counter's increment.
- `histogram(HistogramRecord $histogram): void` records one value of a histogram.

Each record is exported once, in the order given. An export never throws: telemetry must never fail or change the call it describes, which may have committed already, so a record the backend does not take is dropped.

A `SpanRecord` has a `TelemetryName`, its start by the Clock, converted to UTC with microseconds, its duration in nanoseconds of real, monotonic time, 0 or more, a `SpanStatus` (`ok` or `error`) and `Attributes`. A `CounterRecord` has a name, an increment of 1 or more and attributes. A `HistogramRecord` has a name, a finite value of 0 or more, a `MetricUnit` (`ms`, `By` or `1`, the UCUM codes) and attributes.

A `TelemetryName` is lowercase segments of letters, digits and underscores, each starting with a letter and separated by dots, at most 255 characters, such as `cms.command.duration`. The kernel's names start with `cms.`, and an addon uses its own prefix. An `Attribute` has a name and a scalar value: text of valid UTF-8 up to 1024 bytes, an integer, a finite float or a boolean. `Attributes` are sorted by name, and a name given twice is refused with `InvalidTelemetry`.

No attribute holds the value of a field classified above public (PRD 6.5, invariant 10), a secret or personal data (GUARDRAILS 5, 6). The kernel puts only ids, names, codes, counts and times in attributes, and an addon does the same.

## What the pipelines export

The command pipeline and the query pipeline export one span and its metrics for every call, whatever the action, so an action is never instrumented by hand (GUARDRAILS 5). The span is named after the action, such as `entry.create`, starts when the pipeline takes the call and lasts until it answers, its transaction included. It is `ok` for a call that committed, ran dry or was answered, and `error` for a call that was rejected or threw; a call that threw is exported and then throws on.

| Attribute | On | Value |
|---|---|---|
| `cms.action` | every span and metric | the action's name |
| `cms.action.version` | every span and metric | the action's version |
| `cms.action.kind` | every span and metric | `command` or `query` |
| `cms.outcome` | every span and metric | `committed`, `committed_wait_timeout`, `dry_run`, `rejected`, `answered`, or `exception` for a call that threw |
| `cms.error.code` | the span and metrics of a rejected call | the catalog code of its first error |
| `cms.error.count` | the span of a rejected call | how many errors it has |
| `cms.exception.type` | the span and metrics of a call that threw | the exception's class |
| `cms.changeset_id`, `cms.commit_position` | the span of a committed command | its changeset and its commit position |
| `cms.correlation_id` | the span of a command, and of a query whose surface has one | the correlation id of the call |
| `cms.surface`, `cms.issuer_kind`, `cms.wait_level` | the span of a command | from its envelope; a query's span has `cms.surface` when it came through one |
| `cms.classification_access`, `cms.read_position`, `cms.content_keys` | the span of an answered query | the reader's classification access, the read's position and the number of content keys |

The metrics are the histograms `cms.command.duration` and `cms.query.duration` in milliseconds, one value per call, and the counters `cms.command.errors` and `cms.query.errors`, incremented once for each call that was rejected or threw. Metrics carry no ids, so their cardinality stays that of the actions and the error codes. No field value, error message or exception message reaches an attribute, and no actor id does.

The identity module's login policy adds the counter `cms.login.decisions`, incremented once for each decision, with `cms.outcome` `allowed` or `refused` and, for a refusal, `cms.error.code`, the catalog code of the rule that refused it (see [Login policy](../../security/login-policy.md)). It carries no actor id, login identifier or e-mail address.

## What the panel exports

The panel exports, per addon, what it does with the contributions of each page it renders (PRD 13.4):

| Metric | Kind | When |
|---|---|---|
| `cms.panel.data.duration` | histogram, milliseconds | each data query of a contribution, answered or not |
| `cms.panel.data.errors` | counter | each data query that was not answered |
| `cms.panel.contributions.withheld` | counter | each time the panel left contributions off a page it rendered |

| Attribute | On | Value |
|---|---|---|
| `cms.addon`, `cms.panel.contribution`, `cms.panel.point` | the data metrics, and a withheld count of one contribution | the addon's namespace, the contribution's id and the point's id |
| `cms.panel.page` | every panel metric | the page's name |
| `cms.action`, `cms.action.version` | the data metrics | the query's name and version |
| `cms.outcome` | the data metrics | `answered`, `rejected`, `refused` (the panel did not run it) or `failed` (it threw) |
| `cms.error.code` | the data metrics of a rejected query | the catalog code of its first error, such as `query_over_budget` |
| `cms.panel.reason` | a refused query, and every withheld count | for a query, `query_without_codec` or `input_invalid`; for a withheld count, `registry_missing`, `registry_malformed`, `activation_invalid`, `point_without_codec` or `props_unencodable` |

The query pipeline exports each data query's own span and metrics as well. No prop, field value or message reaches an attribute.

## The log exporter: LogTelemetry

`LogTelemetry` writes each record as one log entry whose message names its kind and whose context holds the record, so a log pipeline reads it as structured data:

| Message | Level | Context |
|---|---|---|
| `cms.span` | info, or warning when the status is `error` | `span`, `start` (UTC, `2026-09-30T12:00:00.123456Z`), `duration_ns`, `duration_ms`, `status`, `attributes` |
| `cms.counter` | info | `counter`, `increment`, `attributes` |
| `cms.histogram` | info | `histogram`, `value`, `unit`, `attributes` |

`attributes` maps each name to its value, sorted by name. A pipeline's span carries the changeset and the correlation id, so its entry is the call's structured log. When the logger throws, the record is dropped. The entries go to the default log channel; point that channel at the log pipeline in `config/logging.php`.

## The fake: FakeTelemetry

`Cbox\Cms\Testkit\Telemetry\FakeTelemetry` keeps every record in the order exported. `spans()`, `counters()` and `histograms()` list them, `spansNamed()` gives the spans of one name, `counted()` the sum of a counter's increments and `recorded()` the values of a histogram. `interrupt()` makes it drop every record until `restore()`. This example is in the `Unit` suite:

<!-- example: examples/Unit/Telemetry/TelemetryRecordsTest.php -->
```php
<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Telemetry\Attribute;
use Cbox\Cms\Contracts\Telemetry\Attributes;
use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\InvalidTelemetry;
use Cbox\Cms\Contracts\Telemetry\MetricUnit;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\SpanStatus;
use Cbox\Cms\Contracts\Telemetry\TelemetryName;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;

// An addon's search indexer exports a span and metrics for one batch through the Telemetry
// contract, and its test reads them back from the testkit's fake. The attributes hold ids, codes
// and counts, never a field's value.

it('exports a batch as a span with its metrics, which the fake gives back', function (): void {
    $telemetry = new FakeTelemetry;
    $attributes = new Attributes(Attribute::of('acme.search.index', 'articles'), Attribute::of('acme.search.documents', 20));

    $telemetry->span(new SpanRecord(new TelemetryName('acme.search.batch'), new DateTimeImmutable('2026-09-30 12:00:00 UTC'), 42_000_000, SpanStatus::Ok, $attributes));
    $telemetry->histogram(new HistogramRecord(new TelemetryName('acme.search.batch.duration'), 42.0, MetricUnit::Milliseconds, $attributes));
    $telemetry->counter(new CounterRecord(new TelemetryName('acme.search.documents'), 20, $attributes));

    expect($telemetry->spansNamed('acme.search.batch'))->toHaveCount(1)
        ->and($telemetry->spansNamed('acme.search.batch')[0]->durationMilliseconds())->toBe(42.0)
        ->and($telemetry->spans()[0]->attributes->get('acme.search.index'))->toBe('articles')
        ->and($telemetry->recorded('acme.search.batch.duration'))->toBe([42.0])
        ->and($telemetry->counted('acme.search.documents'))->toBe(20);
});

it('refuses a name that is not dotted lowercase and an attribute given twice', function (): void {
    expect(fn (): TelemetryName => new TelemetryName('Acme Search'))->toThrow(InvalidTelemetry::class)
        ->and(fn (): Attributes => new Attributes(Attribute::of('acme.search.index', 'a'), Attribute::of('acme.search.index', 'b')))->toThrow(InvalidTelemetry::class);
});
```

## Running the shared suite against an exporter

Every exporter runs the shared suite, the trait `Cbox\Cms\Testkit\Telemetry\TelemetryContract`, in a PHPUnit test class in its `tests/Contract` directory. The trait has one abstract method, `telemetry(): TelemetryHarness`, which returns a harness for a new exporter whose backend has taken nothing.

`TelemetryHarness` is the backend's side: `telemetry()` is the exporter under test, `spans()`, `counters()` and `histograms()` list what the backend took, decoded back into records, and `interrupt()` and `restore()` make the backend refuse every record and take them again. The fake is its own harness. The kernel's harness for `LogTelemetry` decodes the entries of a recording logger, and interrupts it by making the logger throw.

The cases cover a span with its name, its start to the microsecond, its duration to the nanosecond, its status and attributes of every scalar type; spans, counters and histograms in the order exported; and a backend that refuses, which never makes an export throw.

This example runs the suite against an exporter that sends every record to two exporters, as an application does while it moves from the log to OpenTelemetry. It is in the `Contract` suite:

<!-- example: examples/Contract/Telemetry/TeeTelemetryContractTest.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Telemetry;

use Cbox\Cms\Testkit\Telemetry\TelemetryContract;
use Cbox\Cms\Testkit\Telemetry\TelemetryHarness;
use Override;
use PHPUnit\Framework\TestCase;

/**
 * The shared Telemetry suite against the application's own exporter, TeeTelemetry over two fakes.
 * The trait brings the cases; the class only says how to make the harness.
 */
final class TeeTelemetryContractTest extends TestCase
{
    use TelemetryContract;

    #[Override]
    protected function telemetry(): TelemetryHarness
    {
        return new TeeTelemetryHarness;
    }
}
```

<!-- example-file: examples/Contract/Telemetry/TeeTelemetry.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Telemetry;

use Cbox\Cms\Contracts\Telemetry\CounterRecord;
use Cbox\Cms\Contracts\Telemetry\HistogramRecord;
use Cbox\Cms\Contracts\Telemetry\SpanRecord;
use Cbox\Cms\Contracts\Telemetry\Telemetry;

/**
 * Exports every record to each of several exporters, in the order given, such as the kernel's log
 * exporter and an OpenTelemetry exporter while an application moves from one to the other. Each
 * exporter never throws, so neither does this one, and a backend that refuses loses only its own
 * copy.
 */
final readonly class TeeTelemetry implements Telemetry
{
    /** @var list<Telemetry> */
    private array $exporters;

    public function __construct(Telemetry ...$exporters)
    {
        $this->exporters = array_values($exporters);
    }

    public function span(SpanRecord $span): void
    {
        foreach ($this->exporters as $exporter) {
            $exporter->span($span);
        }
    }

    public function counter(CounterRecord $counter): void
    {
        foreach ($this->exporters as $exporter) {
            $exporter->counter($counter);
        }
    }

    public function histogram(HistogramRecord $histogram): void
    {
        foreach ($this->exporters as $exporter) {
            $exporter->histogram($histogram);
        }
    }
}
```

<!-- example-file: examples/Contract/Telemetry/TeeTelemetryHarness.php -->
```php
<?php

declare(strict_types=1);

namespace Examples\Contract\Telemetry;

use Cbox\Cms\Contracts\Telemetry\Telemetry;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use Cbox\Cms\Testkit\Telemetry\TelemetryHarness;
use PHPUnit\Framework\Assert;

/**
 * The harness of the shared suite for TeeTelemetry over two fakes: it reads what the first took,
 * and checks that the second took the same records, and interrupt() makes both refuse.
 */
final readonly class TeeTelemetryHarness implements TelemetryHarness
{
    private FakeTelemetry $first;

    private FakeTelemetry $second;

    public function __construct()
    {
        $this->first = new FakeTelemetry;
        $this->second = new FakeTelemetry;
    }

    public function telemetry(): Telemetry
    {
        return new TeeTelemetry($this->first, $this->second);
    }

    public function spans(): array
    {
        Assert::assertSame($this->first->spans(), $this->second->spans());

        return $this->first->spans();
    }

    public function counters(): array
    {
        Assert::assertSame($this->first->counters(), $this->second->counters());

        return $this->first->counters();
    }

    public function histograms(): array
    {
        Assert::assertSame($this->first->histograms(), $this->second->histograms());

        return $this->first->histograms();
    }

    public function interrupt(): void
    {
        $this->first->interrupt();
        $this->second->interrupt();
    }

    public function restore(): void
    {
        $this->first->restore();
        $this->second->restore();
    }
}
```

An application binds its exporter in its own `config/cbox-cms.php`, as `'contracts' => [Telemetry::class => TeeTelemetry::class]`, and gives it its exporters with a contextual binding in its service provider.
