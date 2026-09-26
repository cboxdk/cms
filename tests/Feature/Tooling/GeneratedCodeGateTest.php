<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Generators\Generation\Domain\Dto\GenerationTarget;
use Cbox\Cms\Generators\Generation\Domain\GeneratorRunner;
use Cbox\Cms\Generators\Generation\Domain\Generators\PhpTypeHandleEnum;
use Cbox\Cms\Generators\Generation\Domain\Generators\TypeScriptTypeHandles;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use Symfony\Component\Process\Process;

/*
 * Gate 6 of GUARDRAILS 10 for the workbench (PRD 11.12, GUARDRAILS 7.1): `composer
 * check:generated` fails unless the committed generated code is exactly what cms:generate writes
 * from the committed schema.
 *
 * The gate is run step by step from composer.json in a scratch git repository with a copy of the
 * workbench's schema and generated code, so the test does not depend on the state of this
 * working copy. cms:generate runs in-process with cms.generators.root pointing at the copy.
 */

const GENERATED_PATHS = ['workbench/app/Cms/Generated', 'workbench/resources/js/cms/generated'];

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * @return array<string, mixed>
 */
function composerScripts(): array
{
    $composer = json_decode((string) file_get_contents(Phpstan::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($composer) || ! is_array($composer['scripts'] ?? null)) {
        throw new RuntimeException('composer.json has no scripts.');
    }

    $scripts = [];

    foreach ($composer['scripts'] as $name => $script) {
        $scripts[(string) $name] = $script;
    }

    return $scripts;
}

/**
 * The commands of a Composer script, with @script references expanded.
 *
 * @return list<string>
 */
function scriptSteps(string $name): array
{
    $script = composerScripts()[$name] ?? throw new RuntimeException("composer.json has no script {$name}.");
    $steps = [];

    foreach (is_array($script) ? $script : [$script] as $step) {
        if (! is_string($step)) {
            throw new RuntimeException("Script {$name} has a step that is not a string.");
        }

        $reference = str_starts_with($step, '@') && ! str_starts_with($step, '@php ') ? substr($step, 1) : null;

        array_push($steps, ...($reference !== null && array_key_exists($reference, composerScripts()) ? scriptSteps($reference) : [$step]));
    }

    return $steps;
}

/**
 * A git repository with the workbench's schema and generated code, committed.
 */
function gateRepository(): string
{
    $root = SchemaFixtures::scratch();

    foreach (['workbench/schema/fixture.yaml', 'workbench/app/Cms/Generated/TypeHandle.php', 'workbench/resources/js/cms/generated/index.ts'] as $file) {
        SchemaFixtures::write($root.'/'.$file, (string) file_get_contents(Phpstan::root().'/'.$file));
    }

    git($root, 'init', '--quiet');
    git($root, 'add', '--all');
    git($root, 'commit', '--quiet', '--message=fixture');

    config()->set('cms.generators.root', $root);

    return $root;
}

function git(string $root, string ...$arguments): string
{
    $process = new Process(['git', '-c', 'user.name=Gate Test', '-c', 'user.email=gate@example.test', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', ...array_values($arguments)], $root);
    $process->mustRun();

    return $process->getOutput();
}

/**
 * Runs `composer check:generated` step by step in the repository, and stops at the first step
 * that fails, like Composer does.
 *
 * @return array{int, string} the exit code and the output
 */
function runGate(string $root): array
{
    $output = '';

    foreach (scriptSteps('check:generated') as $step) {
        if (str_starts_with($step, '@php vendor/bin/testbench cms:generate')) {
            $artisan = app(Kernel::class);
            $status = $artisan->call('cms:generate');
            $output .= $artisan->output();
        } else {
            $process = Process::fromShellCommandline($step, $root);
            $status = $process->run();
            $output .= $process->getOutput().$process->getErrorOutput();
        }

        if ($status !== 0) {
            return [$status, $output];
        }
    }

    return [0, $output];
}

function appendTo(string $path, string $text): void
{
    file_put_contents($path, file_get_contents($path).$text);
}

it('regenerates, then fails on a diff or an untracked file under the generated paths, checked before and after', function (): void {
    $paths = implode(' ', GENERATED_PATHS);

    expect(scriptSteps('check:generated'))->toHaveCount(5)
        ->and(scriptSteps('check:generated')[2])->toBe('@php vendor/bin/testbench cms:generate --ansi')
        ->and(scriptSteps('check:generated:committed')[0])->toStartWith('git diff --exit-code -- '.$paths.' || ')
        ->and(scriptSteps('check:generated:committed')[1])->toStartWith('git ls-files --others --exclude-standard -- '.$paths.' | ')
        ->and(scriptSteps('check:generated'))->toBe([
            ...scriptSteps('check:generated:committed'),
            '@php vendor/bin/testbench cms:generate --ansi',
            ...scriptSteps('check:generated:committed'),
        ]);
});

it('points the gate at the directories cms:generate writes in the workbench', function (): void {
    expect([config('cms.generators.php_directory'), config('cms.generators.typescript_directory')])->toBe(GENERATED_PATHS);
});

it('passes on a clean tree and leaves it clean', function (): void {
    $root = gateRepository();

    [$status, $output] = runGate($root);

    expect($status)->toBe(0, $output)
        ->and(git($root, 'status', '--porcelain'))->toBe('');
});

it('fails after a manual edit to a generated PHP file, and passes again after git checkout of it', function (): void {
    $root = gateRepository();
    appendTo($root.'/workbench/app/Cms/Generated/TypeHandle.php', "// edited by hand\n");

    [$edited, $output] = runGate($root);
    git($root, 'checkout', '--', 'workbench/app/Cms/Generated/TypeHandle.php');
    [$restored] = runGate($root);

    expect($edited)->not->toBe(0)
        ->and($output)->toContain('+// edited by hand')
        ->and($output)->toContain('The generated code above is not the committed code.')
        ->and($restored)->toBe(0);
});

it('fails after a field or a type is added to the schema without regenerating', function (string $addition, string $generated): void {
    $root = gateRepository();
    appendTo($root.'/workbench/schema/fixture.yaml', $addition);

    [$status, $output] = runGate($root);

    expect($status)->not->toBe(0)
        ->and($output)->toContain($generated);
})->with([
    'a field' => ["      - handle: summary\n        type: text\n", "+                'summary' => 'text',"],
    'a type' => ["  - handle: page\n    label: Page\n    fields:\n      - handle: title\n        type: text\n", "+    case Page = 'page';"],
]);

it('passes once the regenerated code is staged with the schema change', function (): void {
    $root = gateRepository();
    appendTo($root.'/workbench/schema/fixture.yaml', "      - handle: summary\n        type: text\n");
    app(Kernel::class)->call('cms:generate');
    git($root, 'add', '--all');

    [$status, $output] = runGate($root);

    expect($status)->toBe(0, $output);
});

it('fails on an untracked file in a generated directory', function (): void {
    $root = gateRepository();
    SchemaFixtures::write($root.'/workbench/app/Cms/Generated/Extra.php', "<?php\n");

    [$status, $output] = runGate($root);

    expect($status)->not->toBe(0)
        ->and($output)->toContain('Untracked generated file: workbench/app/Cms/Generated/Extra.php')
        ->and(is_file($root.'/workbench/app/Cms/Generated/Extra.php'))->toBeTrue();
});

it('fails when a file cms:generate writes is not committed', function (): void {
    $root = gateRepository();
    git($root, 'rm', '--quiet', '--cached', 'workbench/resources/js/cms/generated/index.ts');
    git($root, 'commit', '--quiet', '--message=untrack');

    [$status, $output] = runGate($root);

    expect($status)->not->toBe(0)
        ->and($output)->toContain('Untracked generated file: workbench/resources/js/cms/generated/index.ts');
});

it('generates code for a larger schema that Pint, Rector, PHPStan, tsc, ESLint and Prettier accept unchanged', function (): void {
    $types = [];

    foreach (range(1, 12) as $number) {
        $types[sprintf('fairly_long_type_handle_number_%d', $number)] = ['title' => 'text', 'body_'.$number => 'markdown', 'a_field' => 'reference'];
    }

    $directory = SchemaFixtures::scratch();
    $target = new GenerationTarget($directory, 'schema/fixture.yaml', 'app/Generated', 'Cbox\Cms\Probe\Generated', 'js/generated');
    $files = new GeneratorRunner([new PhpTypeHandleEnum, new TypeScriptTypeHandles])->run(SchemaFixtures::schema($types), $target)->files;

    [$php, $typeScript] = [$files[0]->contents, $files[1]->contents];
    $phpFile = $directory.'/TypeHandle.php';
    file_put_contents($phpFile, $php);

    $pint = new Process([Phpstan::root().'/vendor/bin/pint', '--test', '--config='.Phpstan::root().'/pint.json', $phpFile], Phpstan::root());
    $pint->run();
    $rector = new Process([Phpstan::root().'/vendor/bin/rector', 'process', '--dry-run', '--no-progress-bar', $phpFile], Phpstan::root());
    $rector->run();
    $analysis = Phpstan::analyse($phpFile);

    [$lint, $typecheck, $format] = Node::withProbe('ts', $typeScript, static fn (string $path): array => [
        Node::tool('eslint', ['--max-warnings=0', $path]),
        Node::run(['npm', 'run', '--silent', 'typecheck']),
        Node::tool('prettier', ['--check', $path]),
    ]);

    expect($typeScript)->toContain("export type TypeHandle =\n  | 'fairly_long_type_handle_number_1'\n")
        ->and($pint->getExitCode())->toBe(0, $pint->getOutput())
        ->and($rector->getExitCode())->toBe(0, $rector->getOutput())
        ->and($analysis->identifiers)->toBe([])
        ->and($analysis->exitCode)->toBe(0)
        ->and($lint->getExitCode())->toBe(0, $lint->getOutput())
        ->and($typecheck->getExitCode())->toBe(0, $typecheck->getOutput())
        ->and($format->getExitCode())->toBe(0, $format->getOutput());
});
