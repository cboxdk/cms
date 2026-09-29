<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Selftest;

use Cbox\Cms\Tests\Support\Arch\ContentTypeScan;
use Cbox\Cms\Tests\Support\Arch\MarkerScan;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScratchRepository;
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

it('plants IO in a hook and a write to a kernel table outside the kernel, each for its PHPStan rule (PRD 6.3, 6.5 invariants 1 and 13)', function (): void {
    $plants = [];

    foreach (Plants::all() as $plant) {
        foreach ($plant->markers as $marker) {
            $plants[$marker] = $plant;
        }
    }

    expect($plants)->toHaveKeys(['cboxCms.hookIo', 'cboxCms.kernelTableWrite'])
        ->and($plants['cboxCms.hookIo']->gate)->toBe(3)
        ->and($plants['cboxCms.hookIo']->contents)->toContain('implements AuthorizeHook', '$this->cache->has(')
        ->and($plants['cboxCms.kernelTableWrite']->gate)->toBe(3)
        ->and($plants['cboxCms.kernelTableWrite']->path)->toStartWith(Plants::WORKBENCH.'/')
        ->and($plants['cboxCms.kernelTableWrite']->contents)->toContain("->table('nodes')", '->update(');
});

it('plants a marker word of GUARDRAILS 11 that the marker gate reports at the file and line the plant expects', function (): void {
    $plants = array_values(array_filter(Plants::all(), static fn (Plant $plant): bool => $plant->path === Plants::MODULE.'/Domain/MarkerComment.php'));
    $repository = ScratchRepository::make();
    $repository->write('README.md', "# Scratch\n")->commit('initial');

    expect($plants)->toHaveCount(1);

    $plants[0]->plantIn($repository->root);

    expect($plants[0]->gate)->toBe(5)
        ->and($plants[0]->step)->toBe('Arch')
        ->and($plants[0]->markers)->toBe([Plants::MODULE.'/Domain/MarkerComment.php:12: '.strtoupper(MarkerScan::WORDS[0])])
        ->and(MarkerScan::of($repository->root)->hits)->toBe($plants[0]->markers);
});

it('plants a handle of the fixture schema that the content type rule reports at the file and line the plant expects', function (): void {
    $plants = array_values(array_filter(Plants::all(), static fn (Plant $plant): bool => $plant->path === Plants::MODULE.'/Domain/ContentType.php'));
    $worktree = ScratchDirectory::make();

    expect($plants)->toHaveCount(1);

    $plants[0]->plantIn($worktree);

    expect($plants[0]->gate)->toBe(5)
        ->and($plants[0]->step)->toBe('Arch')
        ->and($plants[0]->markers)->toBe([Plants::MODULE.'/Domain/ContentType.php:12: fixture_article'])
        ->and(ContentTypeScan::of($worktree, ContentTypeScan::handlesBelow(Phpstan::root().'/'.ContentTypeScan::SCHEMA))->hits)->toBe($plants[0]->markers);
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
        ->and(is_dir($root.'/'.Plants::MODULE))->toBeFalse()
        ->and(is_dir($root.'/'.Plants::WORKBENCH))->toBeFalse();

    foreach (Plants::all() as $plant) {
        expect(is_file($root.'/'.$plant->path))->toBe($plant->append, $plant->path);
    }
});

it('plants PHP that declares strict types and the namespace of its directory, the core module\'s or the workbench\'s Selftest, and is valid PHP', function (): void {
    $namespaces = [Plants::MODULE => 'Cbox\\Cms\\Core\\Selftest\\', Plants::WORKBENCH => 'Workbench\\App\\Selftest\\'];

    foreach (Plants::all() as $plant) {
        if ($plant->append || ! str_ends_with($plant->path, '.php')) {
            continue;
        }

        $directory = str_starts_with($plant->path, Plants::MODULE.'/') ? Plants::MODULE : Plants::WORKBENCH;
        $tokens = PhpToken::tokenize($plant->contents, TOKEN_PARSE);

        expect($plant->path)->toStartWith($directory.'/')
            ->and($plant->contents)->toStartWith("<?php\n\ndeclare(strict_types=1);\n\nnamespace ".$namespaces[$directory])
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
