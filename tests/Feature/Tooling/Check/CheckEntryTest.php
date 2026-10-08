<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Check\Adapter\SymfonyProcessRunner;
use Cbox\Cms\Tooling\Check\Boundary\CheckOptions;
use Cbox\Cms\Tooling\Check\Boundary\ComposerCommand;
use Cbox\Cms\Tooling\Check\Domain\Profile;
use Cbox\Cms\Tooling\Check\Domain\PrPart;
use Cbox\Cms\Tooling\Check\Domain\ShardPlan;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/*
 * The way into `composer check` and `composer check:selftest`: the Composer scripts, the options,
 * the process runner that runs every gate, and the guides that tell agents about the commands.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
    putenv('COMPOSER_BINARY');
});

it('defines composer check and check:selftest without Composer\'s process timeout', function (): void {
    $composer = json_decode((string) file_get_contents(Phpstan::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $scripts = is_array($composer) && is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];

    expect($scripts['check'] ?? null)->toBe(['Composer\Config::disableProcessTimeout', '@php tools/bin/check.php'])
        ->and($scripts['check:selftest'] ?? null)->toBe(['Composer\Config::disableProcessTimeout', '@php tools/bin/check-selftest.php'])
        ->and(is_file(Phpstan::root().'/tools/bin/check.php'))->toBeTrue()
        ->and(is_file(Phpstan::root().'/tools/bin/check-selftest.php'))->toBeTrue();
});

it('tells agents about composer check, check:selftest and services:up in CLAUDE.md and AGENTS.md', function (string $guide): void {
    expect((string) file_get_contents(Phpstan::root().'/'.$guide))
        ->toContain('composer check', 'composer check:selftest', 'composer services:up');
})->with(['CLAUDE.md', 'AGENTS.md']);

it('parses --report and --brief and refuses anything else', function (): void {
    $options = CheckOptions::parse(['--report=/tmp/report.json', '--brief']);

    expect($options->reportFile)->toBe('/tmp/report.json')
        ->and($options->brief)->toBeTrue()
        ->and(CheckOptions::parse([])->reportFile)->toBeNull()
        ->and(CheckOptions::parse([])->brief)->toBeFalse()
        ->and(static fn (): CheckOptions => CheckOptions::parse(['--report=']))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): CheckOptions => CheckOptions::parse(['--step=3']))->toThrow(InvalidArgumentException::class, 'Unknown option --step=3');
});

it('limits a run to the gates named with --gate, once each, and refuses a gate that is not a number from 1 to 99', function (): void {
    expect(CheckOptions::parse([])->gates)->toBe([])
        ->and(CheckOptions::parse(['--pr', '--gate=7'])->gates)->toBe([7])
        ->and(CheckOptions::parse(['--gate=5', '--gate=3', '--gate=5'])->gates)->toBe([5, 3])
        ->and(CheckOptions::USAGE)->toContain('[--gate=<n>]...')
        ->and(static fn (): CheckOptions => CheckOptions::parse(['--gate=0']))->toThrow(InvalidArgumentException::class, 'Unknown option --gate=0')
        ->and(static fn (): CheckOptions => CheckOptions::parse(['--gate=seven']))->toThrow(InvalidArgumentException::class, 'Unknown option --gate=seven')
        ->and(static fn (): CheckOptions => CheckOptions::parse(['--gate=']))->toThrow(InvalidArgumentException::class);
});

it('runs the local profile unless --pr asks for the PR profile, and refuses --profile, which is Composer\'s', function (): void {
    expect(CheckOptions::parse([])->profile)->toBe(Profile::Local)
        ->and(CheckOptions::parse(['--pr'])->profile)->toBe(Profile::Pr)
        ->and(CheckOptions::parse(['--brief', '--pr', '--report=/tmp/r.json'])->profile)->toBe(Profile::Pr)
        ->and(static fn (): CheckOptions => CheckOptions::parse(['--profile=pr']))->toThrow(InvalidArgumentException::class, 'Unknown option --profile=pr')
        ->and(CheckOptions::USAGE)->toContain('[--pr [--mutation] [--only=gates | --shard=<i>/<n>');
});

it('runs the PR profile without mutation testing unless --mutation opts in, as Sylvester decided on 2 October 2026', function (): void {
    $default = CheckOptions::parse(['--pr']);
    $optIn = CheckOptions::parse(['--pr', '--mutation']);

    expect($default->part)->toEqual(PrPart::withoutMutation())
        ->and($default->part->runsMutation())->toBeFalse()
        ->and($default->part->runsMutationSuite())->toBeFalse()
        ->and($default->mutationReportFile)->toBeNull()
        ->and($optIn->part)->toEqual(PrPart::all())
        ->and($optIn->part->runsMutation())->toBeTrue()
        ->and($optIn->part->runsMutationSuite())->toBeTrue()
        ->and(CheckOptions::parse(['--mutation', '--pr'])->part)->toEqual(PrPart::all())
        ->and(CheckOptions::USAGE)->toContain('[--pr [--mutation');
});

it('picks a part of the PR profile with --only=gates or --shard=<i>/<n>, and the shard\'s report with --shard-report', function (): void {
    $gates = CheckOptions::parse(['--pr', '--only=gates']);
    $shard = CheckOptions::parse(['--pr', '--shard=2/'.ShardPlan::SHARDS, '--shard-report=/tmp/shard.json']);

    expect($gates->part)->toEqual(PrPart::suiteGates())
        ->and($gates->part->runsGates())->toBeTrue()
        ->and($gates->part->isSuiteShard())->toBeFalse()
        ->and($gates->shardReportFile)->toBeNull()
        ->and($shard->part)->toEqual(PrPart::suiteShard(2, ShardPlan::SHARDS))
        ->and($shard->part->isSuiteShard())->toBeTrue()
        ->and($shard->part->runsGates())->toBeFalse()
        ->and($shard->part->runsMutation())->toBeFalse()
        ->and($shard->shardReportFile)->toBe('/tmp/shard.json')
        ->and($shard->mutationReportFile)->toBeNull()
        ->and(CheckOptions::parse(['--pr'])->shardReportFile)->toBeNull()
        ->and(CheckOptions::USAGE)->toContain('--only=gates', '--shard=<i>/<n>', '--shard-report=<file>');
});

it('picks a part of a run with mutation testing with --mutation and --only=gates or --shard=<i>/<n>, and a shard\'s report with --mutation-report', function (): void {
    $all = CheckOptions::parse(['--pr', '--mutation']);
    $gates = CheckOptions::parse(['--pr', '--mutation', '--only=gates']);
    $shard = CheckOptions::parse(['--pr', '--mutation', '--shard=3/12', '--mutation-report=/tmp/shard.json']);

    expect($all->part)->toEqual(PrPart::all())
        ->and($all->mutationReportFile)->toBeNull()
        ->and($gates->part)->toEqual(PrPart::gates())
        ->and($gates->part->runsGates())->toBeTrue()
        ->and($gates->part->runsMutation())->toBeFalse()
        ->and($shard->part)->toEqual(PrPart::shard(3, 12))
        ->and($shard->part->runsGates())->toBeFalse()
        ->and($shard->part->runsMutation())->toBeTrue()
        ->and($shard->mutationReportFile)->toBe('/tmp/shard.json')
        ->and(CheckOptions::USAGE)->toContain('--only=gates', '--shard=<i>/<n>', '--mutation-report=<file>');
});

it('refuses a part without --pr, --mutation without --pr, two parts, a shard outside the plan and a shard report without its shard', function (array $arguments, string $message): void {
    expect(static fn (): CheckOptions => CheckOptions::parse(array_values(array_filter($arguments, is_string(...)))))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a shard of the local profile' => [['--shard=1/2'], 'need --pr'],
    'the gates of the local profile' => [['--only=gates'], 'need --pr'],
    'mutation testing of the local profile' => [['--mutation'], 'needs --pr'],
    'gates and a shard' => [['--pr', '--mutation', '--only=gates', '--shard=1/2'], 'not both or twice'],
    'two shards' => [['--pr', '--mutation', '--shard=1/2', '--shard=2/2'], 'not both or twice'],
    'shard 3 of 2' => [['--pr', '--mutation', '--shard=3/2'], 'Shard 3 of 2 is not a shard'],
    'shard 0' => [['--pr', '--mutation', '--shard=0/2'], 'Unknown option --shard=0/2'],
    'a shard without a count' => [['--pr', '--mutation', '--shard=1'], 'Unknown option --shard=1'],
    'only something else' => [['--pr', '--mutation', '--only=mutation'], 'Unknown option --only=mutation'],
    'a mutation report without a shard' => [['--pr', '--mutation', '--mutation-report=/tmp/r.json'], 'needs --mutation and --shard'],
    'a mutation report of the default run' => [['--pr', '--mutation-report=/tmp/r.json'], 'needs --mutation and --shard'],
    'a mutation report of a shard of the suites' => [['--pr', '--shard=1/4', '--mutation-report=/tmp/r.json'], 'needs --mutation and --shard'],
    'a shard outside the declared plan' => [['--pr', '--shard=1/3'], 'is not the plan of 4 shards that CI runs'],
    'a shard report without a shard' => [['--pr', '--shard-report=/tmp/r.json'], 'needs --shard without --mutation'],
    'a shard report of a mutation shard' => [['--pr', '--mutation', '--shard=1/2', '--shard-report=/tmp/r.json'], 'needs --shard without --mutation'],
]);

it('exits 2 on an unknown option before running any gate', function (): void {
    $process = new Process([PHP_BINARY, 'tools/bin/check.php', '--bogus'], Phpstan::root());
    $process->run();

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('Unknown option --bogus')
        ->and($process->getOutput())->toBe('');
});

it('runs Composer as Composer runs @composer inside a script, and from the PATH outside one', function (): void {
    putenv('COMPOSER_BINARY=/opt/composer.phar');
    $inside = ComposerCommand::resolve('/usr/bin/php');
    putenv('COMPOSER_BINARY');

    expect($inside)->toBe(['/usr/bin/php', '/opt/composer.phar'])
        ->and(ComposerCommand::resolve('/usr/bin/php'))->toBe(['composer']);
});

it('runs a command without a shell and keeps its output and exit code', function (): void {
    $directory = ScratchDirectory::make();
    $echoed = '';
    $outcome = new SymfonyProcessRunner()->run(
        [PHP_BINARY, '-r', 'echo getcwd(), " ", getenv("CHECK_PROBE"), "\n"; fwrite(STDERR, "to stderr\n"); exit(3);'],
        $directory,
        ['CHECK_PROBE' => 'set; not expanded $HOME'],
        static function (string $buffer) use (&$echoed): void {
            $echoed .= $buffer;
        },
    );

    expect($outcome->exitCode)->toBe(3)
        ->and($outcome->succeeded())->toBeFalse()
        ->and($outcome->output)->toContain($directory.' set; not expanded $HOME', 'to stderr')
        ->and($echoed)->toBe($outcome->output)
        ->and($outcome->seconds)->toBeGreaterThan(0.0);
});

it('ends a command that runs too long or does not exist without an exit code', function (): void {
    $slow = new SymfonyProcessRunner(0.5)->run([PHP_BINARY, '-r', 'sleep(5);'], Phpstan::root());
    $missing = new SymfonyProcessRunner()->run(['/nonexistent/cbox-cms-probe'], Phpstan::root());

    expect($slow->exitCode)->toBeNull()
        ->and($slow->timedOut)->toBeTrue()
        ->and($slow->succeeded())->toBeFalse()
        ->and($slow->seconds)->toBeLessThan(4.0)
        ->and($slow->output)->toContain('timed out')
        ->and($missing->succeeded())->toBeFalse();
});
