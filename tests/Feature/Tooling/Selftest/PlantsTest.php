<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Selftest;

use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Check\Domain\CheckReport;
use Cbox\Cms\Tooling\Check\Domain\GateResult;
use Cbox\Cms\Tooling\Check\Domain\LocalProfile;
use Cbox\Cms\Tooling\Check\Domain\ProcessOutcome;
use Cbox\Cms\Tooling\Check\Domain\StepResult;
use Cbox\Cms\Tooling\Selftest\Domain\Plant;
use Cbox\Cms\Tooling\Selftest\Domain\Plants;
use Cbox\Cms\Tooling\Selftest\Domain\PlantVerdict;
use Cbox\Cms\Tooling\Selftest\Domain\SelftestFailed;
use InvalidArgumentException;
use PhpToken;

/*
 * The violations the selftest plants and the verdict on each (GUARDRAILS 7.3, 10). These tests
 * keep the list honest: every gate of the local profile gets a violation, each aimed at a step
 * that exists, and a violation only counts as caught when its own step failed and named it.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

it('plants at least one violation for each gate from 1 to 6 and one for each tool of gates 1 and 4', function (): void {
    $plants = Plants::all();
    $gates = array_map(static fn (Plant $plant): int => $plant->gate, $plants);
    $steps = array_map(static fn (Plant $plant): string => "{$plant->gate} {$plant->step}", $plants);

    expect(array_values(array_unique($gates)))->toBe(range(1, 6))
        ->and($steps)->toContain('1 Pint', '1 Prettier', '2 Rector', '3 PHPStan', '4 tsc', '4 ESLint', '5 Arch', '6 check:generated');
});

it('plants what the task names: mixed, both kinds of phpstan-ignore, a transaction in Actions, any, a layer violation and a generated edit', function (): void {
    $markers = array_merge(...array_map(static fn (Plant $plant): array => $plant->markers, Plants::all()));
    $contents = implode("\n", array_map(static fn (Plant $plant): string => $plant->contents, Plants::all()));

    expect($markers)->toContain('cboxCms.mixed', 'cboxCms.phpstanIgnore', 'cboxCms.transaction', 'no-explicit-any', 'Illuminate\Http', 'SimplifyIfReturnBoolRector')
        ->and($contents)->toContain('// @phpstan-ignore-next-line', '// @phpstan-ignore cboxCms.phpstanIgnore', '->beginTransaction()', '): any {', 'use Illuminate\Http\Request;');
});

it('aims every violation at a step the local profile runs', function (): void {
    $running = [];

    foreach (LocalProfile::gates('php', ['composer']) as $gate) {
        foreach ($gate->steps as $step) {
            if ($step->runs()) {
                $running[] = "{$gate->number} {$step->name}";
            }
        }
    }

    foreach (Plants::all() as $plant) {
        expect($running)->toContain("{$plant->gate} {$plant->step}");
    }
});

it('plants new files where nothing exists in the repository, and appends only to files that do', function (): void {
    $root = Phpstan::root();
    $paths = array_map(static fn (Plant $plant): string => $plant->path, Plants::all());

    expect(array_unique($paths))->toHaveCount(count($paths))
        ->and(is_dir($root.'/'.Plants::MODULE))->toBeFalse();

    foreach (Plants::all() as $plant) {
        expect(is_file($root.'/'.$plant->path))->toBe($plant->append, $plant->path);
    }
});

it('plants PHP that declares strict types and a namespace in the core package, and is valid PHP', function (): void {
    foreach (Plants::all() as $plant) {
        if ($plant->append || ! str_ends_with($plant->path, '.php')) {
            continue;
        }

        $tokens = PhpToken::tokenize($plant->contents, TOKEN_PARSE);

        expect($plant->contents)->toStartWith("<?php\n\ndeclare(strict_types=1);\n\nnamespace Cbox\\Cms\\Core\\Selftest\\")
            ->and($tokens)->not->toBeEmpty();
    }
});

it('writes a plant into a worktree and refuses to overwrite a file or append to a missing one', function (): void {
    $worktree = ScratchDirectory::make();
    $new = new Plant(1, 'Pint', 'new', 'a/b/New.php', "new\n", false, []);
    $append = new Plant(6, 'check:generated', 'append', 'a/b/New.php', "more\n", true, []);

    $new->plantIn($worktree);
    $append->plantIn($worktree);

    expect(file_get_contents($worktree.'/a/b/New.php'))->toBe("new\nmore\n")
        ->and(static fn () => $new->plantIn($worktree))->toThrow(SelftestFailed::class, 'already exists')
        ->and(static fn () => new Plant(6, 'x', 'append', 'missing.php', "x\n", true, [])->plantIn($worktree))->toThrow(SelftestFailed::class, 'does not exist')
        ->and(static fn (): Plant => new Plant(1, 'x', 'escape', '../outside.php', 'x', false, []))->toThrow(InvalidArgumentException::class)
        ->and(static fn (): Plant => new Plant(1, 'x', 'absolute', '/etc/x.php', 'x', false, []))->toThrow(InvalidArgumentException::class);
});

/**
 * A report where step PHPStan of gate 3 ended with the given exit code and output.
 */
function phpstanReport(int $exitCode, string $output): CheckReport
{
    return new CheckReport('/unused', [
        new GateResult(3, 'PHPStan', [StepResult::ran('PHPStan', new ProcessOutcome($exitCode, $output, 1.0))]),
        new GateResult(5, 'Pest', [StepResult::notRun('Mutation', 'mutation testing is not set up')]),
    ]);
}

it('counts a violation as caught only when its step failed, printed every marker and named the file inside the worktree', function (int $exitCode, string $output, bool $caught, string $problem): void {
    $worktree = ScratchDirectory::make();
    $plant = new Plant(3, 'PHPStan', 'mixed in Domain', 'src/Domain/MixedValue.php', "<?php\n", false, ['cboxCms.mixed']);
    $plant->plantIn($worktree);

    $verdict = PlantVerdict::of($plant, phpstanReport($exitCode, $output), $worktree);

    expect($verdict->caught())->toBe($caught)
        ->and($verdict->reportedPath)->toBe($caught ? $worktree.'/src/Domain/MixedValue.php' : $verdict->reportedPath)
        ->and(implode("\n", $verdict->problems))->toContain($problem);
})->with([
    'caught' => [1, "Line src/Domain/MixedValue.php\n 12 uses mixed\n cboxCms.mixed", true, ''],
    'the step passed' => [0, 'src/Domain/MixedValue.php cboxCms.mixed', false, 'PHPStan did not fail'],
    'another rule' => [1, 'src/Domain/MixedValue.php missingType.iterableValue', false, "does not contain 'cboxCms.mixed'"],
    'another file' => [1, 'src/Domain/Other.php cboxCms.mixed', false, 'does not name src/Domain/MixedValue.php'],
]);

it('does not count a violation aimed at a step the report does not have or did not run', function (): void {
    $worktree = ScratchDirectory::make();
    $missing = new Plant(4, 'ESLint', 'any', 'x.ts', "x\n", false, []);
    $notRun = new Plant(5, 'Mutation', 'none', 'y.php', "x\n", false, []);

    expect(PlantVerdict::of($missing, phpstanReport(1, ''), $worktree)->problems)->toBe(['the report has no step ESLint in gate 4'])
        ->and(PlantVerdict::of($notRun, phpstanReport(1, ''), $worktree)->problems)->toContain('Mutation did not fail, its status is not run');
});

it('does not count a violation named in another checkout as well', function (): void {
    $worktree = ScratchDirectory::make();
    $other = ScratchDirectory::make();
    $plant = new Plant(3, 'PHPStan', 'mixed in Domain', 'src/Domain/MixedValue.php', "<?php\n", false, ['cboxCms.mixed']);
    $plant->plantIn($worktree);
    $plant->plantIn($other);

    $verdict = PlantVerdict::of($plant, phpstanReport(1, "src/Domain/MixedValue.php\n{$other}/src/Domain/MixedValue.php cboxCms.mixed"), $worktree);

    expect($verdict->caught())->toBeFalse()
        ->and($verdict->problems)->toBe(["the output of PHPStan names {$other}/src/Domain/MixedValue.php, outside the worktree"]);
});
