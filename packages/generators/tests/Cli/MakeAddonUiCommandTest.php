<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Cli;

use Cbox\Cms\Generators\Cli\Console\MakeAddonUiCommand;
use Cbox\Cms\Generators\Tests\Scaffold\ScaffoldWorld;
use Cbox\Cms\Generators\Tests\SchemaFixtures;
use Cbox\Cms\Tests\Support\Phpstan;
use Illuminate\Contracts\Console\Kernel;
use RuntimeException;
use Symfony\Component\Process\Process;

/*
 * cms:make:addon-ui in the testbench application (PRD 13.4, section 7 of the panel extension
 * architecture): for the addon tally of ScaffoldWorld, whose points take the props types the SDK
 * exports, into a scratch copy of its package. The scaffolded addon is then held to its own gates
 * with the monorepo's node_modules beside it, as an addon's CI runs them: npm run typecheck,
 * npm run lint and npm run test pass on what the scaffold wrote, so a stub is a working start
 * and its test passes the SDK's conformance helper. A second run keeps every file; a namespace
 * no addon has is refused with the catalog's exit code.
 */

afterEach(function (): void {
    SchemaFixtures::cleanUp();
});

/**
 * Runs one of the scaffolded package's npm scripts through the tool it names, from the
 * monorepo's node_modules, and gives the process.
 *
 * @param  list<string>  $command
 */
function runInScaffoldedAddon(string $package, array $command): Process
{
    $root = Phpstan::root();

    if (! is_dir($root.'/node_modules/@cboxdk/cms-panel')) {
        throw new RuntimeException('node_modules is missing or stale. Run `npm ci` in the monorepo root.');
    }

    $command[0] = $root.'/node_modules/.bin/'.$command[0];
    $process = new Process($command, $package, null, null, 300);
    $process->run();

    return $process;
}

it('scaffolds an addon whose typecheck, lint and tests pass, and a second run keeps every file', function (): void {
    $package = ScaffoldWorld::bindToApplication();
    $kernel = app(Kernel::class);

    $first = $kernel->call('cms:make:addon-ui', ['namespace' => ScaffoldWorld::NAMESPACE]);
    $firstOutput = $kernel->output();

    expect($first)->toBe(0)
        ->and($firstOutput)->toContain(
            'written: package.json',
            'written: resources/panel/src/Badge.tsx',
            'written: resources/panel/src/ConfirmStep.test.tsx',
            'written: resources/panel/src/TitleCheckCheck.ts',
            'written: resources/panel/src/index.ts',
            'written: tests/Panel/PanelContributionsTest.php',
            'Scaffolded the panel UI of tally.',
        )
        ->and(is_file($package.'/resources/panel/generated/contributions.ts'))->toBeTrue();

    // The scaffolded package.json names its scripts; the monorepo's node_modules stand in for npm install.
    $manifest = json_decode((string) file_get_contents($package.'/package.json'), true, 8, JSON_THROW_ON_ERROR);
    $scripts = is_array($manifest) ? $manifest['scripts'] ?? null : null;
    expect($scripts)->toBeArray()->toMatchArray(['typecheck' => 'tsc --noEmit', 'lint' => 'eslint --max-warnings=0 .', 'test' => 'vitest run']);
    symlink(Phpstan::root().'/node_modules', $package.'/node_modules');

    $lint = new Process(['php', '-l', $package.'/tests/Panel/PanelContributionsTest.php']);
    $lint->run();
    expect($lint->getExitCode())->toBe(0, $lint->getOutput().$lint->getErrorOutput());

    foreach ([['tsc', '--noEmit'], ['eslint', '--max-warnings=0', '.'], ['vitest', 'run']] as $command) {
        $process = runInScaffoldedAddon($package, $command);

        expect($process->getExitCode())->toBe(0, sprintf("%s failed in the scaffolded addon:\n%s\n%s", $command[0], $process->getOutput(), $process->getErrorOutput()));
    }

    // The stubs' tests ran: one per fill, check and step, and the registration's.
    expect($process->getOutput().$process->getErrorOutput())->toContain('Test Files  4 passed', 'Tests  4 passed');

    unlink($package.'/node_modules');
    $second = $kernel->call('cms:make:addon-ui', ['namespace' => ScaffoldWorld::NAMESPACE]);

    expect($second)->toBe(0)
        ->and($kernel->output())->toContain('kept: package.json', 'kept: resources/panel/src/ids.ts')
        ->and($kernel->output())->not->toContain('written:');
});

it('refuses a namespace no installed addon has, and one that is no namespace', function (string $namespace, int $exit, string $message): void {
    ScaffoldWorld::bindToApplication();
    $kernel = app(Kernel::class);

    expect($kernel->call('cms:make:addon-ui', ['namespace' => $namespace]))->toBe($exit)
        ->and($kernel->output())->toContain($message);
})->with([
    'not installed' => ['reviews', 64, '[generate_panel_addon_unknown] No installed addon has the namespace reviews.'],
    'reserved' => ['app', 64, 'app'],
    'not a namespace' => ['Reviews', 64, 'Reviews'],
]);

it('is registered with the generators', function (): void {
    expect(app(Kernel::class)->all())->toHaveKey('cms:make:addon-ui')
        ->and(app(Kernel::class)->all()['cms:make:addon-ui'])->toBeInstanceOf(MakeAddonUiCommand::class);
});
