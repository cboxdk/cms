<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Telemetry;

use Cbox\Cms\Contracts\Results\WriteResult;
use Cbox\Cms\Contracts\Telemetry\SpanStatus;
use Cbox\Cms\Core\Telemetry\Domain\PipelineTelemetry;
use Cbox\Cms\Core\Tests\Pipeline\Fakes\FakeStopwatch;
use Cbox\Cms\Core\Tests\Pipeline\PipelineWorld;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeBinding;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeCalls;
use Cbox\Cms\Core\Tests\Pipeline\Probe\ProbeShelf;
use Cbox\Cms\Core\Tests\Pipeline\Probe\RenameProbeAction;
use Cbox\Cms\Testkit\Telemetry\FakeTelemetry;
use RuntimeException;

/*
 * PipelineTelemetry around a call that throws: the span names the exception's class, without the
 * path PHP puts in the name of an anonymous class, and lasts until the call threw.
 */

it('names an anonymous exception by its class without the path, and measures until it threw', function (): void {
    $world = new PipelineWorld;
    $telemetry = new FakeTelemetry;
    $stopwatch = new FakeStopwatch;
    $instruments = new PipelineTelemetry($telemetry, $world->clock, $stopwatch);
    $thrown = new class('The disk is full.') extends RuntimeException {};

    expect(fn (): WriteResult => $instruments->command(
        ProbeBinding::of(new RenameProbeAction(new ProbeShelf, new ProbeCalls)),
        $world->call($world->command()),
        static function () use ($stopwatch, $thrown): WriteResult {
            $stopwatch->advanceMilliseconds(3);

            throw $thrown;
        },
    ))->toThrow($thrown::class);

    $span = $telemetry->spans()[0];
    $type = $span->attributes->get(PipelineTelemetry::EXCEPTION);

    expect($span->status)->toBe(SpanStatus::Error)
        ->and($span->durationNanoseconds)->toBe(3_000_000)
        ->and($type)->toBe('RuntimeException@anonymous')
        ->and($telemetry->recorded(PipelineTelemetry::COMMAND_DURATION))->toBe([3.0])
        ->and($telemetry->counters()[0]->attributes->get(PipelineTelemetry::EXCEPTION))->toBe($type);
});
