<?php

declare(strict_types=1);

use Cbox\Cms\Tests\Support\Phpstan;

/*
 * Gates 1 to 3 of GUARDRAILS 10 for the monorepo: Pint, Rector and PHPStan level 10 without
 * a baseline, all on the shared configuration from the testkit. These tests guard the
 * configuration itself (GUARDRAILS 7.3), so a change that weakens it fails here.
 */

/**
 * @return array<string, mixed>
 */
function rootComposer(): array
{
    $composer = json_decode((string) file_get_contents(Phpstan::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($composer)) {
        throw new UnexpectedValueException('composer.json is not a JSON object.');
    }

    /** @var array<string, mixed> $composer */
    return $composer;
}

/**
 * Every file in the monorepo outside vendor/, node_modules/ and .git/, relative to the root.
 *
 * @return list<string>
 */
function repositoryFiles(): array
{
    $root = Phpstan::root();
    $directories = new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => ! in_array($file->getFilename(), ['vendor', 'node_modules', '.git'], true),
    );

    $files = [];
    foreach (new RecursiveIteratorIterator($directories) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    return $files;
}

it('analyses every package, the tests, the tooling and the workbench at level 10 with no ignored errors', function (): void {
    $root = Phpstan::root();
    $parameters = Phpstan::parameters('phpstan.neon');
    $packageDirectories = array_merge(
        glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: [],
        glob($root.'/packages/*/tests', GLOB_ONLYDIR) ?: [],
    );
    // rector.php covers these by glob; PHPStan does not expand globs, so each one must be listed.
    $supportDirectories = array_merge(
        glob($root.'/packages/*/bin', GLOB_ONLYDIR) ?: [],
        glob($root.'/packages/*/config', GLOB_ONLYDIR) ?: [],
        glob($root.'/packages/*/database', GLOB_ONLYDIR) ?: [],
    );

    expect($packageDirectories)->toHaveCount(12)
        ->and($supportDirectories)->toContain($root.'/packages/core/config', $root.'/packages/core/database', $root.'/packages/testkit/bin')
        ->and($parameters->value('level'))->toBe(10)
        ->and($parameters->value('ignoreErrors'))->toBe([])
        ->and($parameters->strings('analysedPathsFromConfig'))
        ->toContain(...[...$packageDirectories, ...$supportDirectories, $root.'/tests', $root.'/tools', $root.'/workbench']);
});

it('analyses every root PHP file that Rector covers with withRootFiles() at level 10', function (): void {
    $root = Phpstan::root();
    // RectorConfigBuilder::withRootFiles() adds each *.php and .*.php in the root but .phpstorm.meta.php,
    // and Pint formats them too; PHPStan analyses only the paths listed, so each one must be listed.
    $rootFiles = array_values(array_filter(
        glob($root.'/{,.}*.php', GLOB_BRACE) ?: [],
        static fn (string $file): bool => is_file($file) && basename($file) !== '.phpstorm.meta.php',
    ));

    expect((string) file_get_contents($root.'/rector.php'))->toContain('->withRootFiles()')
        ->and($rootFiles)->toContain($root.'/rector.php')
        ->and(Phpstan::parameters('phpstan.neon')->strings('analysedPathsFromConfig'))->toContain(...$rootFiles);
});

it('uses the shared configuration from the testkit for all three tools', function (): void {
    $parameters = Phpstan::parameters('phpstan.neon');
    $pint = json_decode((string) file_get_contents(Phpstan::root().'/pint.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($parameters->strings('bootstrapFiles'))->toContain(Phpstan::root().'/vendor/larastan/larastan/bootstrap.php')
        ->and($pint)->toBe(['extend' => 'vendor/cboxdk/cms-testkit/config/pint.json', 'cache-file' => '.cache/pint/pint.cache'])
        ->and((string) file_get_contents(Phpstan::root().'/rector.php'))
        ->toContain("require __DIR__.'/vendor/cboxdk/cms-testkit/config/rector.php'");
});

it('has no baseline file and no neon file that includes one', function (): void {
    $files = repositoryFiles();
    $neonFiles = array_filter($files, static fn (string $file): bool => str_contains($file, '.neon'));

    expect(array_filter($files, static fn (string $file): bool => stripos(basename($file), 'baseline') !== false))->toBe([])
        ->and($neonFiles)->not->toBeEmpty();

    foreach ($neonFiles as $file) {
        expect((string) file_get_contents(Phpstan::root().'/'.$file))
            ->not->toMatch('/^\s*-\s*\S*baseline\S*\s*$/im');
    }
});

it('fails the analysis on a method call on mixed, and passes once the value is narrowed', function (): void {
    $directory = sys_get_temp_dir().'/cms-phpstan-probe-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $probe = $directory.'/Probe.php';

    try {
        file_put_contents($probe, <<<'PHP'
            <?php

            declare(strict_types=1);

            function cmsPhpstanProbe(mixed $value): mixed
            {
                return $value->run();
            }
            PHP);
        $failing = Phpstan::analyse($probe);

        file_put_contents($probe, <<<'PHP'
            <?php

            declare(strict_types=1);

            function cmsPhpstanProbe(mixed $value): mixed
            {
                return is_object($value) && method_exists($value, 'run') ? $value->run() : null;
            }
            PHP);
        $clean = Phpstan::analyse($probe);
    } finally {
        if (is_file($probe)) {
            unlink($probe);
        }

        rmdir($directory);
    }

    expect($failing->exitCode)->not->toBe(0)
        ->and($failing->identifiers)->toBe(['method.nonObject'])
        ->and($clean->exitCode)->toBe(0)
        ->and($clean->identifiers)->toBe([]);
});

it('exposes gates 1 to 3 as composer scripts', function (): void {
    expect(rootComposer()['scripts'] ?? null)->toBeArray()
        ->toMatchArray([
            'lint' => '@php tools/bin/pint.php',
            'lint:check' => '@php tools/bin/pint.php --test',
            'rector:check' => '@php vendor/bin/rector process --dry-run',
            'analyse' => '@php vendor/bin/phpstan analyse --no-progress',
        ]);
});

it('keeps the tools out of production by requiring the testkit for development only', function (): void {
    $composer = rootComposer();

    expect($composer['require'] ?? null)->toBeArray();
    expect($composer['require'])->not->toHaveKey('cboxdk/cms-testkit');
    expect($composer['require-dev'] ?? null)->toBeArray()->toHaveKey('cboxdk/cms-testkit');
});
