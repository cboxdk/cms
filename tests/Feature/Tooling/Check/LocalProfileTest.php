<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\PhpunitSuites;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Check\Domain\Gate;
use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use Cbox\Cms\Tooling\Check\Domain\Step;
use RuntimeException;
use UnexpectedValueException;

/*
 * The local profile of GUARDRAILS 10 (`composer check`): gates 1 to 6 in order, with the
 * commands the gates are defined by. These tests guard the profile itself (GUARDRAILS 7.3): a
 * gate or suite left out, or a skipped test allowed through, fails here.
 */

const COMPOSER = ['/usr/bin/php', '/usr/bin/composer'];

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * @return list<Gate>
 */
function localGates(): array
{
    return LocalProfile::gates('/usr/bin/php', COMPOSER);
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
        ->and($scripts['lint:check'] ?? null)->toBe('@php tools/bin/pint.php --test --parallel')
        ->and($scripts['rector:check'] ?? null)->toBe('@php vendor/bin/rector process --dry-run')
        ->and($scripts['analyse'] ?? null)->toBe('@php vendor/bin/phpstan analyse --no-progress')
        ->and($npmScripts)->toBeArray()
        ->and(is_array($npmScripts) ? array_intersect_key($npmScripts, array_flip(['format:check', 'typecheck', 'lint'])) : [])->toBe([
            'typecheck' => 'tsc --noEmit && tsc --noEmit -p tests/Browser/Fixtures/PanelModules',
            'lint' => 'eslint --max-warnings=0 .',
            'format:check' => 'prettier --check .',
        ]);
});

it('runs each Pest suite on its own in gate 5, after the installation check and the JS unit suite, in parallel workers, and fails a suite with a skipped or incomplete test', function (): void {
    $commands = stepCommands(localGate(5));

    expect(array_keys($commands))->toBe([LocalProfile::INSTALLATION, LocalProfile::VITEST, 'Unit', 'Codecs', 'Contract', 'Postgres', 'Arch', 'Actions'])
        ->and(LocalProfile::FAIL_FLAGS)->toBe(['--fail-on-skipped', '--fail-on-incomplete'])
        ->and(LocalProfile::PARALLEL)->toBe('--parallel');

    foreach (LocalProfile::SUITES as $suite) {
        expect($commands[$suite])->toBe(['/usr/bin/php', 'vendor/bin/pest', '--testsuite='.$suite, '--fail-on-skipped', '--fail-on-incomplete', '--parallel']);
    }
});

it('runs the JS unit suite in gate 5 as Vitest\'s unit project through npm run test:js, failing a skipped test as the Pest suites do', function (): void {
    $scripts = Node::jsonFile('package.json')['scripts'] ?? [];

    expect(LocalProfile::VITEST)->toBe('Vitest')
        ->and(localGate(5)->title)->toBe('Pest and Vitest')
        ->and(stepCommands(localGate(5))[LocalProfile::VITEST])->toBe(['npm', 'run', 'test:js'])
        ->and(is_array($scripts) ? ($scripts['test:js'] ?? null) : null)->toBe('vitest run --project=unit --reporter=default --reporter=./js/tooling/vitest-no-skipped.js');
});

it('runs a suite in one process only when asked, with the same flags otherwise', function (): void {
    expect(LocalProfile::suiteStep('/usr/bin/php', 'Unit', parallel: false)->command)->toBe(['/usr/bin/php', 'vendor/bin/pest', '--testsuite=Unit', '--fail-on-skipped', '--fail-on-incomplete'])
        ->and(LocalProfile::suiteStep('/usr/bin/php', 'Unit')->command)->toBe(['/usr/bin/php', 'vendor/bin/pest', '--testsuite=Unit', '--fail-on-skipped', '--fail-on-incomplete', '--parallel']);
});

it('checks in gate 5, before the suites load any class, that vendor/ is the installation composer.lock and composer.json describe', function (): void {
    $steps = localGate(5)->steps;

    expect(LocalProfile::INSTALLATION)->toBe('Installation')
        ->and($steps[0]->name)->toBe(LocalProfile::INSTALLATION)
        ->and($steps[0]->runs())->toBeTrue()
        ->and($steps[0]->command)->toBe([...COMPOSER, 'install:check']);
});

it('runs the Actions suite as an ordinary step of gate 5, never as not run', function (): void {
    $steps = localGate(5)->steps;

    expect(LocalProfile::SUITES)->toContain('Actions')
        ->and(array_filter($steps, static fn (Step $step): bool => ! $step->runs()))->toBe([])
        ->and(array_last($steps)?->name)->toBe('Actions')
        ->and(array_last($steps)?->command)->toBe(['/usr/bin/php', 'vendor/bin/pest', '--testsuite=Actions', '--fail-on-skipped', '--fail-on-incomplete', '--parallel']);
});

it('covers every suite in phpunit.xml except Browser, which is gate 8, and Mutation, which needs a coverage driver and runs in the PR profile', function (): void {
    $suites = PhpunitSuites::in(Phpstan::root().'/phpunit.xml');
    $covered = [...LocalProfile::SUITES, ...LocalProfile::OTHER_SUITES];

    expect(array_values(array_diff($suites, $covered)))->toBe([])
        ->and($suites)->toContain(...LocalProfile::SUITES)
        ->and(LocalProfile::OTHER_SUITES)->toBe(['Browser', 'Mutation']);
});

it('reads the suite names from phpunit.xml and refuses a file that is not XML', function (): void {
    $directory = ScratchDirectory::make();
    ScratchDirectory::write($directory.'/phpunit.xml', '<phpunit><testsuites><testsuite name="Unit"/><testsuite name="Arch"/></testsuites></phpunit>');
    ScratchDirectory::write($directory.'/broken.xml', '<phpunit>');

    expect(PhpunitSuites::in($directory.'/phpunit.xml'))->toBe(['Unit', 'Arch'])
        ->and(static fn (): array => PhpunitSuites::in($directory.'/broken.xml'))->toThrow(UnexpectedValueException::class)
        ->and(static fn (): array => PhpunitSuites::in($directory.'/missing.xml'))->toThrow(UnexpectedValueException::class);
});
