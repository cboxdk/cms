<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Arch\MarkerScan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tests\Support\Tooling\ScratchRepository;
use Symfony\Component\Process\Process;

/*
 * The marker gate of GUARDRAILS 11 (tests/Arch/MarkersTest.php) on scratch repositories: which
 * files it reads, what it matches and what it leaves out. The words are joined from parts, so
 * this file passes the gate it tests.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

/**
 * Joins a word written with a bar between its parts.
 */
function marker(string $parts): string
{
    return str_replace('|', '', $parts);
}

/**
 * A scratch repository with one commit.
 */
function markerRepository(): ScratchRepository
{
    $repository = ScratchRepository::make('cbox-cms-marker-test-');
    $repository->write('README.md', "# Scratch\n")->commit('initial');

    return $repository;
}

/**
 * @param  list<string>  $paths
 * @return list<string>
 */
function sortedPaths(array $paths): array
{
    sort($paths, SORT_STRING);

    return $paths;
}

it('matches the five words of GUARDRAILS 11, and the fourth in the plural', function (): void {
    expect(MarkerScan::WORDS)->toBe([marker('to|do'), marker('fix|me'), marker('x|xx'), marker('place|holder'), marker('provi|sional')])
        ->and(MarkerScan::PLURAL)->toBe(marker('place|holder'));
});

it('leaves out exactly Markdown, the two lock files, .claude/ and .harness/', function (): void {
    expect(array_keys(MarkerScan::EXCLUDED))->toBe(['*.md', 'composer.lock', 'package-lock.json', '.claude/', '.harness/']);
});

it('fails on a marker in a PHP path, a YAML config file and a script without an extension, naming file:line', function (string $word): void {
    $repository = markerRepository();
    $repository
        ->write('packages/core/src/Scratch/Domain/Note.php', "<?php\n\ndeclare(strict_types=1);\n\n// {$word}: finish this\n")
        ->write('config/scratch.yaml', "scratch:\n  mode: strict\n  # {$word}\n")
        ->write('bin/scratch', "echo start\necho '{$word}'\n");
    chmod($repository->root.'/bin/scratch', 0o755);
    $repository->commit('markers');

    $scan = MarkerScan::of($repository->root);

    expect($scan->files)->toBe(['bin/scratch', 'config/scratch.yaml', 'packages/core/src/Scratch/Domain/Note.php'])
        ->and($scan->hits)->toBe([
            "bin/scratch:2: {$word}",
            "config/scratch.yaml:3: {$word}",
            "packages/core/src/Scratch/Domain/Note.php:5: {$word}",
        ]);
})->with([
    'the first word in upper case' => [marker('TO|DO')],
    'the second word in mixed case' => [marker('Fix|Me')],
    'the third word in lower case' => [marker('x|xx')],
    'the fourth word' => [marker('Place|holder')],
    'the fourth word in the plural' => [marker('place|holders')],
    'the fifth word in upper case' => [marker('PROVI|SIONAL')],
]);

it('reads a new file that git does not ignore, and leaves out an ignored one', function (): void {
    $repository = markerRepository();
    $repository->write('.gitignore', "/ignored/\n")->commit('ignore');
    $repository
        ->write('src/New.php', '// '.marker('fix|me')."\n")
        ->write('ignored/Old.php', '// '.marker('fix|me')."\n");

    $scan = MarkerScan::of($repository->root);

    expect($scan->files)->toBe(['src/New.php'])
        ->and($scan->hits)->toBe(['src/New.php:1: '.marker('fix|me')]);
});

it('passes Markdown, the lock files, .claude/ and .harness/ with the same words', function (): void {
    $words = implode(' ', [marker('TO|DO'), marker('FIX|ME'), marker('X|XX'), marker('place|holder'), marker('place|holders'), marker('provi|sional')]);
    $repository = markerRepository();

    foreach (['notes.md', 'docs/Guide.MD', 'composer.lock', 'package-lock.json', '.claude/workflows/flow.js', '.harness/stop-hook.sh'] as $path) {
        $repository->write($path, $words."\n");
    }

    $repository->commit('excluded');
    $scan = MarkerScan::of($repository->root);

    expect($scan->files)->toBe([])
        ->and($scan->hits)->toBe([])
        ->and(MarkerScan::hitsIn('notes.md', $words))->toHaveCount(1);
});

it('matches whole words only, as git grep -w does', function (): void {
    $lines = [
        'a '.marker('to|dos').' list',
        'size '.marker('x|xxl').' and '.marker('xx|xx'),
        marker('provi|sionally').' agreed',
        marker('place|holder').'_text and my'.marker('fix|me'),
        '$'.marker('to|do').' = 1;',
        '<input '.marker('place|holder').'="Name">',
        '"'.marker('provi|sional').'": true',
        'id-'.marker('x|xx').'-1',
        'nothing here',
    ];
    $repository = markerRepository();

    foreach ($lines as $index => $line) {
        $repository->write("lines/{$index}.txt", $line."\n");
    }

    $repository->commit('lines');
    $pattern = implode('|', [...array_filter(MarkerScan::WORDS, static fn (string $word): bool => $word !== MarkerScan::PLURAL), MarkerScan::PLURAL.'s?']);
    $grep = new Process(['git', 'grep', '-l', '-i', '-w', '-E', $pattern, '--', 'lines'], $repository->root);
    $grep->run();
    $flagged = array_values(array_filter(
        array_keys($lines),
        static fn (int $index): bool => MarkerScan::hitsIn('line', $lines[$index]) !== [],
    ));

    expect($flagged)->toBe([4, 5, 6, 7])
        ->and(array_values(array_filter(explode("\n", $grep->getOutput()))))->toBe(array_map(static fn (int $index): string => "lines/{$index}.txt", $flagged));
});

it('reports every line with a marker and every marker on a line', function (): void {
    $contents = implode("\n", ['one', '// '.marker('TO|DO').' and '.marker('FIX|ME'), 'three', '# '.marker('x|xx')]);

    expect(MarkerScan::hitsIn('a/b.php', $contents))->toBe([
        'a/b.php:2: '.marker('TO|DO').', '.marker('FIX|ME'),
        'a/b.php:4: '.marker('x|xx'),
    ]);
});

it('selects code and configuration by extension, name, directory, execute bit and shebang', function (): void {
    $repository = markerRepository();
    $selected = [
        '.github/CODEOWNERS', '.github/workflows/ci.yml', 'Dockerfile', 'bin/ci', 'bin/run', 'composer.json',
        'docker/ci.Dockerfile', 'docker/php/Dockerfile', 'package.json', 'src/a.cjs', 'src/a.css', 'src/a.html',
        'src/a.js', 'src/a.json', 'src/a.mjs', 'src/a.neon', 'src/a.php', 'src/a.sh', 'src/a.sql', 'src/a.ts',
        'src/a.tsx', 'src/a.xml', 'src/a.yaml', 'src/a.yml', 'src/b.PHP',
    ];
    $left = ['.editorconfig', '.gitignore', 'LICENSE', 'notes.txt', 'src/a.md', 'src/image.png', 'tools/data'];

    foreach ([...$selected, ...$left] as $path) {
        $repository->write($path, "content\n");
    }

    $repository->write('bin/run', "#!/usr/bin/env bash\necho run\n");
    chmod($repository->root.'/bin/ci', 0o755);
    symlink('src/a.php', $repository->root.'/link.php');
    $repository->commit('files');

    expect($repository->git('ls-files', '--stage', '--', 'bin/ci'))->toStartWith('100755')
        ->and($repository->git('ls-files', '--stage', '--', 'link.php'))->toStartWith('120000')
        ->and(MarkerScan::of($repository->root)->files)->toBe(sortedPaths($selected));
});

it('decides the selection from the path, the execute bit and the first bytes', function (string $path, bool $executable, string $head, bool $selected): void {
    expect(MarkerScan::selects($path, $executable, $head))->toBe($selected);
})->with([
    'an executable script without an extension' => ['bin/ci', true, 'se', true],
    'a script without an extension that starts with a shebang' => ['docker/run', false, '#!', true],
    'a file without an extension, not executable and without a shebang' => ['LICENSE', false, 'MI', false],
    'an executable file with another extension' => ['tools/run.txt', true, '#!', false],
    'Markdown under .github' => ['.github/README.md', false, '# ', false],
    'a shell script under .harness' => ['.harness/stop-hook.sh', true, '#!', false],
    'a JavaScript file under .claude' => ['.claude/workflows/cms-milestone.js', false, '//', false],
    'a lock file below the root, which has no selected extension' => ['packages/core/composer.lock', false, '{', false],
    'a package-lock.json below the root' => ['js/tooling/package-lock.json', false, '{', true],
]);
