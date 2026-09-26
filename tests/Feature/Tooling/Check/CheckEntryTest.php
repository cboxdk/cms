<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Check;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Check\Adapter\SymfonyProcessRunner;
use Cbox\Cms\Tooling\Check\Boundary\CheckOptions;
use Cbox\Cms\Tooling\Check\Boundary\ComposerCommand;
use Cbox\Cms\Tooling\Check\Boundary\PhpunitSuites;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

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
        ->and(static fn (): CheckOptions => CheckOptions::parse(['--gate=3']))->toThrow(InvalidArgumentException::class, 'Unknown option --gate=3');
});

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

it('reads the suite names from phpunit.xml and refuses a file that is not XML', function (): void {
    $directory = ScratchDirectory::make();
    ScratchDirectory::write($directory.'/phpunit.xml', '<phpunit><testsuites><testsuite name="Unit"/><testsuite name="Arch"/></testsuites></phpunit>');
    ScratchDirectory::write($directory.'/broken.xml', '<phpunit>');

    expect(PhpunitSuites::in($directory.'/phpunit.xml'))->toBe(['Unit', 'Arch'])
        ->and(static fn (): array => PhpunitSuites::in($directory.'/broken.xml'))->toThrow(UnexpectedValueException::class)
        ->and(static fn (): array => PhpunitSuites::in($directory.'/missing.xml'))->toThrow(UnexpectedValueException::class);
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
