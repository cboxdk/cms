<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tests\Support\Tooling\ScriptedProcessRunner;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\GateSelection;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\PrProfile;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use InvalidArgumentException;

/*
 * `composer check -- --gate=<n>` (GateSelection): a run limited to some gates keeps every gate and
 * step of its profile in the report, with the gates left out reported as not run, and runs only
 * the commands of the gates it names. composer check:selftest runs gate 7 of the PR profile this
 * way.
 */

it('keeps the gates it names and reports every step of the others as not run, by the same names', function (): void {
    $gates = PrProfile::gates('/usr/bin/php', ['/usr/bin/composer']);
    $only = GateSelection::only($gates, [7]);

    expect(array_map(static fn (Gate $gate): int => $gate->number, $only))->toBe(range(1, 11))
        ->and($only[6])->toEqual($gates[6]);

    foreach ($only as $index => $gate) {
        if ($gate->number === 7) {
            continue;
        }

        expect(array_map(static fn (Step $step): string => $step->name, $gate->steps))->toBe(array_map(static fn (Step $step): string => $step->name, $gates[$index]->steps))
            ->and(array_unique(array_map(static fn (Step $step): ?string => $step->notRunReason, $gate->steps)))->toBe([GateSelection::NOT_SELECTED]);
    }
});

it('selects every gate without a number, and refuses a number the profile has no gate for', function (): void {
    $gates = PrProfile::gates('/usr/bin/php', ['/usr/bin/composer']);

    expect(GateSelection::only($gates, []))->toBe($gates)
        ->and(static fn (): array => GateSelection::only($gates, [7, 12]))->toThrow(InvalidArgumentException::class, 'The profile has no gate 12.');
});

it('runs only the commands of the selected gates and passes when they pass', function (): void {
    $runner = new ScriptedProcessRunner(static fn (array $command): ProcessOutcome => new ProcessOutcome(0, 'ok', 0.1));
    $report = new CheckRunner($runner, new SilentListener)->run(GateSelection::only(PrProfile::gates('/usr/bin/php', ['/usr/bin/composer']), [7]), '/srv/checkout');

    expect($runner->commandLines())->toBe(['npm run storybook:build', 'npm run storybook:exports', 'npm run storybook:stories'])
        ->and($report->passed())->toBeTrue()
        ->and($report->gate(7)?->status())->toBe(StepStatus::Pass)
        ->and($report->gate(5)?->status())->toBe(StepStatus::NotRun);
});

it('names the gates of a limited run for the header, sorted and once each', function (): void {
    expect(GateSelection::description([]))->toBe('')
        ->and(GateSelection::description([7]))->toBe('; limited to gate 7 with --gate')
        ->and(GateSelection::description([5, 3, 5]))->toBe('; limited to gates 3, 5 with --gate');
});
