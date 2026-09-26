<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tooling\Check\Boundary\PhpunitSuites;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use Cbox\Cms\Tooling\Check\Domain\Step;
use RuntimeException;

/*
 * The local profile of GUARDRAILS 10 (`composer check`): gates 1 to 6 in order, with the
 * commands the gates are defined by. These tests guard the profile itself (GUARDRAILS 7.3): a
 * gate or suite left out, or a skipped test allowed through, fails here.
 */

const COMPOSER = ['/usr/bin/php', '/usr/bin/composer'];

/**
 * @param  list<string>  $suites
 * @return list<Gate>
 */
function localGates(array $suites = ['Unit', 'Codecs', 'Contract', 'Postgres', 'Arch', 'Browser']): array
{
    return LocalProfile::gates('/usr/bin/php', COMPOSER, $suites);
}

function localGate(int $number): Gate
{
    foreach (localGates() as $gate) {
        if ($gate->number === $number) {
            return $gate;
        }
    }

    throw new RuntimeException("No gate {$number}.");
}

/**
 * @return array<string, list<string>>
 */
function stepCommands(Gate $gate): array
{
    $commands = [];

    foreach ($gate->steps as $step) {
        $commands[$step->name] = $step->command;
    }

    return $commands;
}

it('has gates 1 to 11 in order and runs exactly gates 1 to 6', function (): void {
    $gates = localGates();
    $running = array_filter($gates, static fn (Gate $gate): bool => array_filter($gate->steps, static fn (Step $step): bool => $step->runs()) !== []);

    expect(array_map(static fn (Gate $gate): int => $gate->number, $gates))->toBe(range(1, 11))
        ->and(array_values(array_map(static fn (Gate $gate): int => $gate->number, $running)))->toBe(range(1, 6));

    foreach (array_slice($gates, 6) as $gate) {
        expect($gate->steps)->toHaveCount(1)
            ->and($gate->steps[0]->notRunReason)->toBe(LocalProfile::OUTSIDE_PROFILE);
    }
});

it('runs Pint and Prettier, Rector, PHPStan, tsc and ESLint through the Composer and npm scripts of gates 1 to 4', function (): void {
    expect(stepCommands(localGate(1)))->toBe([
        'Pint' => [...COMPOSER, 'lint:check'],
        'Prettier' => ['npm', 'run', 'format:check'],
    ])
        ->and(stepCommands(localGate(2)))->toBe(['Rector' => [...COMPOSER, 'rector:check']])
        ->and(stepCommands(localGate(3)))->toBe(['PHPStan' => [...COMPOSER, 'analyse']])
        ->and(stepCommands(localGate(4)))->toBe([
            'tsc' => ['npm', 'run', 'typecheck'],
            'ESLint' => ['npm', 'run', 'lint'],
        ])
        ->and(stepCommands(localGate(6)))->toBe(['check:generated' => [...COMPOSER, 'check:generated']]);
});

it('uses scripts that exist and run the gate commands in composer.json and package.json', function (): void {
    $composer = json_decode((string) file_get_contents(Phpstan::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
    $npmScripts = Node::jsonFile('package.json')['scripts'] ?? null;

    expect($scripts)->toHaveKeys(['lint:check', 'rector:check', 'analyse', 'check:generated'])
        ->and($scripts['lint:check'] ?? null)->toBe('@php vendor/bin/pint --test')
        ->and($scripts['rector:check'] ?? null)->toBe('@php vendor/bin/rector process --dry-run')
        ->and($scripts['analyse'] ?? null)->toBe('@php vendor/bin/phpstan analyse --no-progress')
        ->and($npmScripts)->toBeArray()
        ->and(is_array($npmScripts) ? array_intersect_key($npmScripts, array_flip(['format:check', 'typecheck', 'lint'])) : [])->toBe([
            'typecheck' => 'tsc --noEmit',
            'lint' => 'eslint --max-warnings=0 .',
            'format:check' => 'prettier --check .',
        ]);
});

it('runs each Pest suite on its own in gate 5 and fails a suite with a skipped or incomplete test', function (): void {
    $commands = stepCommands(localGate(5));

    expect(array_keys($commands))->toBe(['Unit', 'Codecs', 'Contract', 'Postgres', 'Arch', 'Actions']);

    foreach (LocalProfile::SUITES as $suite) {
        expect($commands[$suite])->toBe(['/usr/bin/php', 'vendor/bin/pest', '--testsuite='.$suite, '--fail-on-skipped', '--fail-on-incomplete']);
    }
});

it('reports the Actions suite as not run until phpunit.xml has it, and runs it once it does', function (): void {
    $without = LocalProfile::gates('php', COMPOSER, ['Unit'])[4];
    $with = LocalProfile::gates('php', COMPOSER, ['Unit', 'Actions'])[4];

    expect($without->number)->toBe(5)
        ->and(array_last($without->steps)?->name)->toBe('Actions')
        ->and(array_last($without->steps)?->notRunReason)->toBe('no tests until M1')
        ->and(array_last($without->steps)?->runs())->toBeFalse()
        ->and(array_last($with->steps)?->runs())->toBeTrue()
        ->and(array_last($with->steps)?->command)->toBe(['php', 'vendor/bin/pest', '--testsuite=Actions', '--fail-on-skipped', '--fail-on-incomplete']);
});

it('covers every suite in phpunit.xml except Browser, which is gate 8', function (): void {
    $suites = PhpunitSuites::in(Phpstan::root().'/phpunit.xml');
    $covered = [...LocalProfile::SUITES, LocalProfile::ACTIONS_SUITE, ...LocalProfile::OTHER_SUITES];

    expect(array_values(array_diff($suites, $covered)))->toBe([])
        ->and($suites)->toContain(...LocalProfile::SUITES)
        ->and(LocalProfile::OTHER_SUITES)->toBe(['Browser']);
});
