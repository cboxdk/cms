<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Docs;

use Cbox\Cms\Core\Doctor\Domain\Checks\PostgresVersionCheck;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Docs\Boundary\DocsRequirementsOptions;
use Cbox\Cms\Tooling\Docs\Boundary\RequirementsSources;
use Cbox\Cms\Tooling\Docs\Domain\Requirements;
use Cbox\Cms\Tooling\Docs\Domain\RequirementsPage;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;
use UnexpectedValueException;

/*
 * docs/requirements.md is written by `composer docs:requirements` (tools/bin/docs-requirements.php)
 * from composer.json, package.json and compose.yaml, and states only what the resolver enforces,
 * the development tools and services, and the minimums of cms:doctor (the cboxdk docs standard).
 * The committed page must be exactly what the script writes now, so a requirement added to
 * composer.json without running the script fails here.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

function requirementsRead(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Cannot read {$path}.");
    }

    return $contents;
}

/**
 * A scratch tree with this repository's composer.json, package.json, compose.yaml and committed
 * docs/requirements.md.
 */
function requirementsTree(): string
{
    $root = ScratchDirectory::make();

    foreach (['composer.json', 'package.json', 'compose.yaml', RequirementsPage::PATH] as $file) {
        ScratchDirectory::write($root.'/'.$file, requirementsRead(Phpstan::root().'/'.$file));
    }

    return $root;
}

/**
 * Adds entries to the require section of the tree's composer.json.
 *
 * @param  array<string, string>  $entries
 */
function requirementsRequire(string $root, array $entries): void
{
    $composer = json_decode(requirementsRead($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    expect($composer)->toBeArray();
    $composer = is_array($composer) ? $composer : [];
    $require = is_array($composer['require'] ?? null) ? $composer['require'] : [];
    $composer['require'] = [...$require, ...$entries];

    file_put_contents($root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}

/**
 * Runs tools/bin/docs-requirements.php of this checkout with the arguments.
 *
 * @return array{int, string, string}
 */
function runDocsRequirements(string ...$arguments): array
{
    $process = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/docs-requirements.php', ...array_values($arguments)], Phpstan::root(), null, null, 60);
    $process->run();

    return [$process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput()];
}

it('commits docs/requirements.md exactly as composer docs:requirements writes it', function (): void {
    $expected = RequirementsPage::render(RequirementsSources::read(Phpstan::root()));

    expect(requirementsRead(Phpstan::root().'/'.RequirementsPage::PATH))->toBe($expected);
});

it('lists on docs/requirements.md every requirement and suggestion of composer.json', function (): void {
    $composer = json_decode(requirementsRead(Phpstan::root().'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];
    $suggest = is_array($composer) && is_array($composer['suggest'] ?? null) ? $composer['suggest'] : [];
    $page = requirementsRead(Phpstan::root().'/'.RequirementsPage::PATH);

    expect($require)->not->toBe([])->and($suggest)->not->toBe([]);

    $missing = [];

    foreach ($require as $name => $constraint) {
        $constraint = is_string($constraint) ? $constraint : '';
        $row = match (true) {
            $name === 'php' => '| PHP | `'.$constraint.'` |',
            str_starts_with((string) $name, 'ext-') => '| PHP extension `'.substr((string) $name, strlen('ext-')).'` | `'.str_replace('|', '\|', $constraint).'` |',
            default => '| `'.$name.'` | `'.str_replace('|', '\|', $constraint).'` |',
        };

        if (! str_contains($page, $row)) {
            $missing[] = $row;
        }
    }

    foreach (array_keys($suggest) as $name) {
        if (! str_contains($page, '| `'.$name.'` | ')) {
            $missing[] = (string) $name;
        }
    }

    expect($missing)->toBe([]);
});

it('fails the committed page when composer.json gains a requirement, and lists it once the script ran', function (): void {
    $root = requirementsTree();
    $committed = requirementsRead($root.'/'.RequirementsPage::PATH);

    expect(RequirementsPage::render(RequirementsSources::read($root)))->toBe($committed);

    requirementsRequire($root, ['acme/widget' => '^1.2', 'ext-intl' => '*']);
    $expected = RequirementsPage::render(RequirementsSources::read($root));

    expect($expected)->not->toBe($committed)
        ->and($committed)->not->toContain('acme/widget')
        ->and($expected)->toContain('| `acme/widget` | `^1.2` |', '| PHP extension `intl` | `*` |')
        ->and($expected)->not->toContain('requires no PHP extension');

    [$exit, $output, $errors] = runDocsRequirements('--root='.$root);

    expect([$exit, $errors])->toBe([0, ''])
        ->and($output)->toContain('wrote docs/requirements.md')
        ->and(requirementsRead($root.'/'.RequirementsPage::PATH))->toBe($expected);

    [$exit, $output] = runDocsRequirements('--root='.$root);

    expect($exit)->toBe(0)
        ->and($output)->toContain('docs/requirements.md is current')
        ->and(requirementsRead($root.'/'.RequirementsPage::PATH))->toBe($expected);
});

it('fails the committed page when package.json or compose.yaml change', function (): void {
    $root = requirementsTree();
    $committed = requirementsRead($root.'/'.RequirementsPage::PATH);

    file_put_contents($root.'/package.json', str_replace('"node": "^22.13.0 || >=24"', '"node": ">=26"', requirementsRead($root.'/package.json')));

    $page = RequirementsPage::render(RequirementsSources::read($root));

    expect($page)->not->toBe($committed)
        ->and($page)->toContain('- Node `>=26`, as `engines`');

    $root = requirementsTree();
    file_put_contents($root.'/compose.yaml', str_replace('ghcr.io/cboxdk/valkey:8', 'ghcr.io/cboxdk/valkey:9', requirementsRead($root.'/compose.yaml')));

    $page = RequirementsPage::render(RequirementsSources::read($root));

    expect($page)->not->toBe($committed)
        ->and($page)->toContain('| `valkey` | `ghcr.io/cboxdk/valkey:9` |');
});

it('writes PHP, Laravel, the extensions and the other platform requirements before the packages', function (): void {
    $page = RequirementsPage::render(new Requirements(
        ['php' => '^8.5', 'illuminate/support' => '^13.0', 'illuminate/database' => '^13.1', 'ext-pdo_pgsql' => '*', 'composer-runtime-api' => '^2.2', 'symfony/process' => '^7.4 || ^8.0'],
        ['symfony/yaml' => 'Reads | pipes.'],
        '>=22',
        ['postgres' => 'ghcr.io/cboxdk/postgres:18', 'builder' => 'local:tag'],
    ));

    expect($page)->toStartWith("---\ntitle: Requirements\nweight: 3\ndescription: ")
        ->toContain(
            "| Requirement | Constraint |\n|---|---|\n| PHP | `^8.5` |\n| Laravel, through the `illuminate/*` packages | `^13.0`, `^13.1` |\n| `composer-runtime-api` | `^2.2` |\n| PHP extension `pdo_pgsql` | `*` |\n",
            "| Package | Constraint |\n|---|---|\n| `illuminate/database` | `^13.1` |\n| `illuminate/support` | `^13.0` |\n| `symfony/process` | `^7.4 \\|\\| ^8.0` |\n",
            '| `symfony/yaml` | Reads \| pipes. |',
            '- Node `>=22`, as `engines` in `package.json`',
            "| `builder` | `local:tag` |\n| `postgres` | `ghcr.io/cboxdk/postgres:18` |",
            sprintf('supports Postgres %d as the minimum', PostgresVersionCheck::MINIMUM_MAJOR),
        )
        ->toEndWith("fail without them.\n")
        ->and($page)->not->toContain('requires no PHP extension', '| `php` |', '| `ext-pdo_pgsql` |');
});

it('says what composer.json, package.json and compose.yaml leave out', function (): void {
    $page = RequirementsPage::render(new Requirements([], [], null, []));

    expect($page)->toContain(
        '| PHP | any version: `composer.json` does not constrain it |',
        '`composer.json` requires no PHP extension.',
        '`composer.json` requires no package.',
        '`composer.json` suggests no package.',
        '`package.json` states no version in `engines`.',
    )->and($page)->not->toContain('Laravel, through', 'Docker runs the services', 'as the minimum: it uses nothing');
});

it('gives the same bytes for the same requirements, whatever the order of the files', function (): void {
    $first = RequirementsPage::render(new Requirements(['b/b' => '1', 'a/a' => '2'], ['d/d' => 'x', 'c/c' => 'y'], null, ['z' => 'i', 'y' => 'j']));
    $second = RequirementsPage::render(new Requirements(['a/a' => '2', 'b/b' => '1'], ['c/c' => 'y', 'd/d' => 'x'], null, ['y' => 'j', 'z' => 'i']));

    expect($first)->toBe($second)
        ->and(strpos($first, '`a/a`'))->toBeLessThan((int) strpos($first, '`b/b`'));
});

it('refuses a tree it cannot read', function (string $file, string $contents, string $message): void {
    $root = requirementsTree();
    file_put_contents($root.'/'.$file, $contents);

    expect(static fn (): Requirements => RequirementsSources::read($root))->toThrow(UnexpectedValueException::class, $message);
})->with([
    'composer.json is not JSON' => ['composer.json', '{', 'composer.json is not valid JSON'],
    'require is not an object' => ['composer.json', '{"require": "php"}', 'composer.json require is not an object.'],
    'a constraint is not a string' => ['composer.json', '{"require": {"php": 8}}', 'composer.json require has an entry that is not a name and a string.'],
    'engines is not an object' => ['package.json', '{"engines": "node"}', 'package.json engines is not an object.'],
    'engines.node is not a string' => ['package.json', '{"engines": {"node": 22}}', 'package.json engines.node is not a string.'],
    'compose.yaml has no services' => ['compose.yaml', "name: x\n", 'compose.yaml has no services.'],
    'compose.yaml is not YAML' => ['compose.yaml', "services: [\n", 'compose.yaml cannot be read'],
]);

it('refuses a root that is not a directory, and a tree without composer.json', function (): void {
    $root = ScratchDirectory::make();

    expect(static fn (): Requirements => RequirementsSources::read($root.'/missing'))->toThrow(UnexpectedValueException::class, 'is not a directory.')
        ->and(static fn (): Requirements => RequirementsSources::read($root))->toThrow(UnexpectedValueException::class, 'Cannot read composer.json');

    [$exit, , $errors] = runDocsRequirements('--root='.$root);

    expect($exit)->toBe(1)->and($errors)->toContain('docs:requirements: Cannot read composer.json');
});

it('exits 2 with the usage on an unknown or repeated argument', function (string $message, string ...$arguments): void {
    [$exit, , $errors] = runDocsRequirements(...$arguments);

    expect($exit)->toBe(2)->and($errors)->toContain($message, DocsRequirementsOptions::USAGE);
})->with([
    'unknown' => ['Unknown or repeated argument [--check].', '--check'],
    'empty root' => ['Unknown or repeated argument [--root=].', '--root='],
    'repeated root' => ['Unknown or repeated argument [--root=b].', '--root=a', '--root=b'],
]);

it('parses the root', function (): void {
    expect(DocsRequirementsOptions::parse([])->root)->toBeNull()
        ->and(DocsRequirementsOptions::parse(['--root=/x'])->root)->toBe('/x')
        ->and(static fn (): DocsRequirementsOptions => DocsRequirementsOptions::parse(['x']))->toThrow(InvalidArgumentException::class);
});

it('runs tools/bin/docs-requirements.php as composer docs:requirements, with a description', function (): void {
    expect(ComposerScripts::steps('docs:requirements'))->toBe(['@php tools/bin/docs-requirements.php'])
        ->and(ComposerScripts::description('docs:requirements'))->toContain('docs/requirements.md', 'composer.json', 'package.json', 'compose.yaml', '--root=<dir>');
});
