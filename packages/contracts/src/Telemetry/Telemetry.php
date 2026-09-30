<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Telemetry;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * Telemetry export (GUARDRAILS 2.3 and 5): where the kernel's spans, counters and histograms go.
 * The command and query pipelines give every action a span and metrics for its duration and its
 * errors through it, so an action is never instrumented by hand.
 *
 * - span() exports one finished span: its name, when it started, how long it took, its status
 *   and its attributes.
 * - counter() adds a counter's increment under its attributes.
 * - histogram() records one value of a histogram under its attributes.
 *
 * Each record is exported once, in the order given. An export never throws: telemetry must never
 * fail or change the call it describes, which may already have committed, so a record the backend
 * does not take is dropped. No attribute ever holds the value of a field classified above public
 * (invariant 10), a secret or personal data (GUARDRAILS 5, 6); the callers put only ids, names,
 * codes, counts and times in attributes.
 */
#[Experimental]
interface Telemetry
{
    public function span(SpanRecord $span): void;

    public function counter(CounterRecord $counter): void;

    public function histogram(HistogramRecord $histogram): void;
}
