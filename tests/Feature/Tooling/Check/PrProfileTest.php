<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use Cbox\Cms\Tooling\Check\Domain\Profile;
use Cbox\Cms\Tooling\Check\Domain\PrProfile;
use Cbox\Cms\Tooling\Check\Domain\ReportFormatter;
use Cbox\Cms\Tooling\Check\Domain\Step;

/*
 * The PR profile as CI runs it through bin/ci (`composer check -- --pr`): the steps of gates 1 to
 * 6 are the local profile's, so CI and a developer run the same commands, and everything else of
 * the PR profile in GUARDRAILS 10 is reported as not run with a reason.
 */

const PR_COMPOSER = ['/usr/bin/php', '/usr/bin/composer'];

/**
 * @return list<Gate>
 */
function prGates(): array
{
    return PrProfile::gates('/usr/bin/php', PR_COMPOSER);
}

/**
 * @param  list<Step>  $steps
 * @return list<array{string, list<string>, ?string}>
 */
function stepTriples(array $steps): array
{
    return array_map(static fn (Step $step): array => [$step->name, $step->command, $step->notRunReason], $steps);
}

it('runs the local profile\'s steps for gates 1 to 6, unchanged, and adds only mutation to gate 5', function (): void {
    $local = LocalProfile::gates('/usr/bin/php', PR_COMPOSER);
    $pr = prGates();

    expect(array_map(static fn (Gate $gate): int => $gate->number, $pr))->toBe(range(1, 11));

    foreach ([1, 2, 3, 4, 6] as $number) {
        expect($pr[$number - 1])->toEqual($local[$number - 1]);
    }

    $gate5 = $pr[4];
    $mutation = array_last($gate5->steps);

    expect($gate5->title)->toBe($local[4]->title)
        ->and(stepTriples(array_slice($gate5->steps, 0, -1)))->toBe(stepTriples($local[4]->steps))
        ->and($mutation?->name)->toBe(PrProfile::MUTATION)
        ->and($mutation?->runs())->toBeFalse()
        ->and($mutation?->notRunReason)->toBe(PrProfile::MUTATION_NOT_RUN);
});

it('reports gates 7 to 11 as not run, each with its own reason and never the local profile\'s', function (): void {
    $outside = array_slice(prGates(), 6);

    expect(array_keys(PrProfile::NOT_RUN))->toBe(range(7, 11));

    foreach ($outside as $gate) {
        expect($gate->steps)->toHaveCount(1)
            ->and($gate->steps[0]->runs())->toBeFalse()
            ->and($gate->steps[0]->notRunReason)->toBe(PrProfile::NOT_RUN[$gate->number])
            ->and($gate->steps[0]->notRunReason)->not->toBe(LocalProfile::OUTSIDE_PROFILE);
    }

    expect(array_unique(PrProfile::NOT_RUN))->toHaveCount(5);
});

it('picks the gates by profile and names the profile in the header', function (): void {
    expect(Profile::Local->gates('/usr/bin/php', PR_COMPOSER))->toEqual(LocalProfile::gates('/usr/bin/php', PR_COMPOSER))
        ->and(Profile::Pr->gates('/usr/bin/php', PR_COMPOSER))->toEqual(prGates())
        ->and(ReportFormatter::header('/repo'))->toBe("composer check: the local profile of GUARDRAILS 10, gates 1 to 6, in /repo\n")
        ->and(ReportFormatter::header('/repo', Profile::Pr))->toBe("composer check: the PR profile of GUARDRAILS 10 as CI runs it today, gates 1 to 6, with 7 to 11 reported as not run, in /repo\n");
});
