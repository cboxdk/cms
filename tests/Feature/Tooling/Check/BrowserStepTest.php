<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ParallelWorker;
use Cbox\Cms\Tests\Support\Tooling\Processes;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Check\Adapter\SymfonyProcessRunner;
use Cbox\Cms\Tooling\Check\Domain\CheckRunner;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\PrProfile;
use Cbox\Cms\Tooling\Check\Domain\Step;
use Cbox\Cms\Tooling\Check\Domain\StepStatus;
use Cbox\Cms\Tooling\Mutation\Domain\MutationScope;
use RuntimeException;

/*
 * Gate 8 for real (M0-T20, M0-T46): the Browser step's Pest process starts the browser plugin's
 * `playwright run-server` and dies. Pest's shutdown handler stops the server after a PHP fatal
 * error, but a process killed at a container's memory limit gets no shutdown, and before the step
 * ran in a process group of its own the server outlived it and held the output of bin/ci open.
 * The step must fail within 60 seconds and leave no Playwright process running.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

it('fails a Browser step whose Pest dies, within 60 seconds, and leaves no Playwright process running', function (string $how): void {
    $pids = ScratchDirectory::make().'/playwright.pids';
    // Gate 8 builds the panel first; the Browser suite is the step named after it.
    $browser = array_first(array_filter(
        PrProfile::gates(PHP_BINARY, ['composer'], MutationScope::changed('the base', []))[7]->steps,
        static fn (Step $step): bool => $step->name === PrProfile::BROWSER_SUITE,
    )) ?? throw new RuntimeException('Gate 8 has no Browser step.');
    // The Browser step of the PR profile, with the fixture in place of the suite, run as its own
    // Pest run also when this test runs in a parallel worker.
    $step = Step::run('Browser', [
        '/usr/bin/env', ...ParallelWorker::unsetArguments(), "CMS_BROWSER_FATAL_PIDS={$pids}", "CMS_BROWSER_FATAL_HOW={$how}",
        PHP_BINARY, 'vendor/bin/pest', 'tests/Feature/Tooling/fixtures/browser-plugin-fatal.php', '--colors=never',
    ], ownProcessGroup: $browser->ownProcessGroup);
    $started = hrtime(true);

    $report = new CheckRunner(new SymfonyProcessRunner(60.0), new QuietListener)->run([new Gate(8, 'Browser tests', [$step])], Phpstan::root());
    $seconds = (hrtime(true) - $started) / 1e9;
    $result = $report->gate(8)?->step('Browser');
    $playwright = Processes::idsIn($pids);

    expect($result?->status)->toBe(StepStatus::Fail)
        ->and($result?->reason)->toBeNull()
        ->and($seconds)->toBeLessThan(60.0)
        ->and($playwright)->not->toBe([], 'The fixture found no Playwright server: '.($result->output ?? ''))
        ->and(Processes::running($playwright))->toBe([]);
})->with([
    'killed as at a container\'s memory limit' => 'killed',
    'a PHP fatal error for exhausted memory' => 'fatal',
]);
