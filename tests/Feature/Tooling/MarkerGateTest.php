<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Arch\InputHintAttributes;
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

it('fails on a marker in a configuration file whatever its name, naming file:line', function (string $path): void {
    $word = marker('to|do');
    $repository = markerRepository();
    $repository->write($path, "# settings\n# {$word}: decide\n")->commit('configuration');

    $scan = MarkerScan::of($repository->root);

    expect($scan->files)->toBe([$path])
        ->and($scan->hits)->toBe(["{$path}:2: {$word}"]);
})->with([
    'a PHP ini file' => ['docker/php/conf.d/cms.ini'],
    'a Postgres conf file' => ['docker/postgres/conf.d/cms.conf'],
    'an ini file below tools' => ['tools/mutation/pcov.ini'],
    'an example environment file' => ['workbench/.env.example'],
    'the Prettier configuration' => ['.prettierrc'],
    'the Prettier ignore file' => ['.prettierignore'],
    'the git ignore file' => ['.gitignore'],
    'the editor configuration' => ['.editorconfig'],
    'a PHPStan rule fixture' => ['packages/testkit/tests/Phpstan/Fixtures/Layers.php.inc'],
    'a text file' => ['notes.txt'],
    'a file without an extension that is not executable' => ['tools/data'],
]);

it('reads a new file that git does not ignore, and leaves out an ignored one', function (): void {
    $repository = markerRepository();
    $repository->write('.gitignore', "/ignored/\n")->commit('ignore');
    $repository
        ->write('src/New.php', '// '.marker('fix|me')."\n")
        ->write('ignored/Old.php', '// '.marker('fix|me')."\n");

    $scan = MarkerScan::of($repository->root);

    expect($scan->files)->toBe(['.gitignore', 'src/New.php'])
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

it('selects every text file git lists whatever its name, and leaves out binary files, Markdown and symlinks', function (): void {
    $repository = markerRepository();
    $selected = [
        '.editorconfig', '.github/CODEOWNERS', '.github/workflows/ci.yml', '.gitignore', '.prettierignore', '.prettierrc',
        'Dockerfile', 'LICENSE', 'bin/ci', 'bin/run', 'composer.json', 'docker/ci.Dockerfile', 'docker/php/Dockerfile',
        'docker/php/conf.d/cms.ini', 'docker/postgres/conf.d/cms.conf', 'notes.txt', 'package.json', 'src/a.cjs',
        'src/a.css', 'src/a.html', 'src/a.js', 'src/a.json', 'src/a.mjs', 'src/a.neon', 'src/a.php', 'src/a.sh',
        'src/a.sql', 'src/a.ts', 'src/a.tsx', 'src/a.xml', 'src/a.yaml', 'src/a.yml', 'src/b.PHP', 'src/empty.txt',
        'tests/Fixtures/Rule.php.inc', 'tools/data', 'workbench/.env.example',
    ];

    foreach ([...$selected, 'src/a.md'] as $path) {
        $repository->write($path, "content\n");
    }

    $repository
        ->write('bin/run', "#!/usr/bin/env bash\necho run\n")
        ->write('src/empty.txt', '')
        ->write('src/image.png', "\x89PNG\r\n\x1a\n\0\0\0\rIHDR")
        ->write('src/late.bin', str_repeat('a', MarkerScan::BINARY_PROBE_BYTES - 1)."\0")
        ->write('src/text-after-probe.txt', str_repeat('a', MarkerScan::BINARY_PROBE_BYTES)."\0");
    chmod($repository->root.'/bin/ci', 0o755);
    symlink('src/a.php', $repository->root.'/link.php');
    $repository->commit('files');

    expect($repository->git('ls-files', '--stage', '--', 'bin/ci'))->toStartWith('100755')
        ->and($repository->git('ls-files', '--stage', '--', 'link.php'))->toStartWith('120000')
        ->and(MarkerScan::of($repository->root)->files)->toBe(sortedPaths([...$selected, 'src/text-after-probe.txt']));
});

it('decides the selection from the path and the first bytes', function (string $path, string $head, bool $selected): void {
    expect(MarkerScan::selects($path, $head))->toBe($selected);
})->with([
    'an executable script without an extension' => ['bin/ci', 'set -e', true],
    'a file without an extension that is not executable' => ['LICENSE', 'MIT License', true],
    'an ini file' => ['docker/php/conf.d/cms.ini', 'allow_url_fopen = Off', true],
    'a conf file' => ['docker/postgres/conf.d/cms.conf', 'max_connections = 100', true],
    'an env example' => ['workbench/.env.example', 'APP_ENV=local', true],
    'a dotfile' => ['.prettierrc', '{', true],
    'an empty file' => ['tests/Contract/.gitkeep', '', true],
    'a binary file' => ['public/logo.png', "\x89PNG\r\n\x1a\n\0\0", false],
    'Markdown under .github' => ['.github/README.md', '# ', false],
    'a shell script under .harness' => ['.harness/stop-hook.sh', '#!', false],
    'a JavaScript file under .claude' => ['.claude/workflows/cms-milestone.js', '//', false],
    'a lock file below the root, which EXCLUDED lists only at the root' => ['packages/core/composer.lock', '{', true],
    'a package-lock.json below the root' => ['js/tooling/package-lock.json', '{', true],
]);

/**
 * The contents with every `{w}` replaced by the fourth word, and every `{W}` by it in upper case.
 */
function withHintWord(string $contents): string
{
    return str_replace(['{w}', '{W}'], [marker('place|holder'), strtoupper(marker('place|holder'))], $contents);
}

it('passes the input hint attribute in .tsx and .html files', function (): void {
    $repository = markerRepository();
    $repository
        ->write('resources/js/Search.tsx', withHintWord(<<<'TSX'
            import type { ReactElement } from "react";

            interface SearchProps {
              label: string;
              hint?: string;
            }

            const inputProps = { type: "search", name: "q" };

            export function Search({ label, hint }: SearchProps): ReactElement {
              return (
                <label>
                  {label}
                  <input
                    name="q"
                    {w}="Title or slug"
                    aria-label={label}
                  />
                  <input {w}={label} {...inputProps} />
                  <input {w}='Author' />
                  <input
                    {w}={hint ?? "A long hint that Prettier puts on its own line"}
                  />
                </label>
              );
            }

            TSX))
        ->write('public/form.html', withHintWord(<<<'HTML'
            <!doctype html>
            <form>
              <label>Name <input type="text" {w}="Your name"></label>
              <textarea name="note" {w}='A short note'></textarea>
              <input
                type="email"
                {w}="you@example.com"
              >
            </form>

            HTML))
        ->commit('forms');

    $scan = MarkerScan::of($repository->root);

    expect($scan->files)->toBe(['public/form.html', 'resources/js/Search.tsx'])
        ->and($scan->hits)->toBe([])
        ->and(InputHintAttributes::EXTENSIONS)->toBe(['html', 'tsx']);
});

it('fails the word in a PHP comment, and the attribute in every file type but .tsx and .html', function (string $path, string $contents): void {
    $repository = markerRepository();
    $repository->write($path, withHintWord($contents))->commit('word');

    expect(MarkerScan::of($repository->root)->hits)->toBe(["{$path}:2: ".marker('place|holder')]);
})->with([
    'a PHP line comment' => ['packages/core/src/Scratch/Domain/Form.php', "<?php\n// {w}: finish the form\n"],
    'a PHP block comment' => ['packages/core/src/Scratch/Domain/Form.php', "<?php\n/* {w}=\"Name\" */\n"],
    'the attribute in a PHP string' => ['packages/core/src/Scratch/Domain/Form.php', "<?php\necho '<input {w}=\"Name\">';\n"],
    'the attribute in a Blade view' => ['resources/views/form.blade.php', "<form>\n<input {w}=\"Name\">\n"],
    'the attribute in a .ts file' => ['resources/js/form.ts', "const a = 1;\nconst b = <input {w}=\"Name\" />;\n"],
    'the attribute in a .jsx file' => ['resources/js/Form.jsx', "const a = 1;\nconst b = <input {w}=\"Name\" />;\n"],
    'the attribute in a .htm file' => ['public/form.htm', "<form>\n<input {w}=\"Name\">\n"],
    'the prop key in a .ts file' => ['resources/js/props.ts', "export const props = {\n  {w}: \"Name\",\n};\n"],
    'the prop key in a .html file' => ['public/form.html', "<script>\nconst props = { {w}: \"Name\" };\n</script>\n"],
]);

it('fails the word anywhere else in a .tsx file', function (string $line, string $words): void {
    $contents = withHintWord("import type { ReactElement } from \"react\";\n{$line}\n");

    expect(MarkerScan::hitsIn('resources/js/Form.tsx', $contents))->toBe(['resources/js/Form.tsx:2: '.withHintWord($words)]);
})->with([
    'a line comment' => ['// {w}: finish the form', '{w}'],
    'a line comment with the attribute' => ['const a = 1; // <input {w}="Name" />', '{w}'],
    'a block comment' => ['/* {w}: finish the form */', '{w}'],
    'a JSX comment with the attribute' => ['const a = <div>{/* <input {w}="Name" /> */}</div>;', '{w}'],
    'a variable' => ['const {w} = "Search";', '{w}'],
    'the attribute value' => ['const a = <input {w}={{w}} />;', '{w}'],
    'a shorthand property' => ['const props = { {w} };', '{w}'],
    'a ternary' => ['const a = ok ? {w} : other;', '{w}'],
    'a member access' => ['input.{w} = "Name";', '{w}'],
    'JSX text' => ['const a = <p>A {w} for the list</p>;', '{w}'],
    'JSX text that reads like the prop key' => ['const a = <p>{w}: soon</p>;', '{w}'],
    'JSX text after an apostrophe' => ['const a = <p>Don\'t <input {w}="Name" /></p>;', '{w}'],
    'a double-quoted string' => ['const a = "{w}: none";', '{w}'],
    'a single-quoted string with the attribute' => ['const a = \' {w}="Name"\';', '{w}'],
    'a template literal' => ['const a = `<input {w}="Name">`;', '{w}'],
    'a template literal around an expression' => ['const a = `${b} {w}={c} ${`{w}: d`}`;', '{w}, {w}'],
    'the attribute in upper case' => ['const a = <input {W}="Name" />;', '{W}'],
    'the attribute in the plural' => ['const a = <input {w}s="Name" />;', '{w}s'],
    'the attribute with spaces around =' => ['const a = <input {w} = "Name" />;', '{w}'],
    'the prop key with a space before the colon' => ['const props = { {w} : "Name" };', '{w}'],
    'the prop key in the plural' => ['const props = { {w}s: "Name" };', '{w}s'],
    'a key first in an object literal' => ['const state = { {w}: true };', '{w}'],
    'a key after a comma in an object literal' => ['const inputProps = { type: "search", {w}: "Search entries" };', '{w}'],
    'a key in an object literal spread onto an input' => ['const a = <input {...{ {w}: "Name" }} />;', '{w}'],
    'an optional member of a type literal' => ['type Row = { {w}?: Draft };', '{w}'],
    'a member after a semicolon in a type literal' => ['type Props = { label: string; {w}: string };', '{w}'],
    'an optional member of an interface' => ['interface SearchProps { label: string; {w}?: string }', '{w}'],
]);

it('fails a key named after the word in an object literal, a props type or an interface in a .tsx file', function (): void {
    $contents = withHintWord(<<<'TSX'
        interface SearchProps {
          label: string;
          {w}?: string;
        }

        type Row = {
          {w}?: Draft;
        };

        const state = {
          {w}: true,
        };

        const fieldProps = {
          name: "q",
          {w}:
            "A long hint that Prettier puts on its own line",
        };

        TSX);

    expect(InputHintAttributes::offsets('resources/js/Search.tsx', $contents))->toBe([])
        ->and(MarkerScan::hitsIn('resources/js/Search.tsx', $contents))->toBe(array_map(
            static fn (int $line): string => 'resources/js/Search.tsx:'.$line.': '.marker('place|holder'),
            [3, 7, 11, 16],
        ));
});

it('passes the attribute after a template literal around an expression in a .tsx file', function (): void {
    $contents = withHintWord('const a = `${b} ${`c`}`;'."\n".'const i = <input {w}="Name" />;'."\n");

    expect(MarkerScan::hitsIn('resources/js/Form.tsx', $contents))->toBe([])
        ->and(InputHintAttributes::offsets('resources/js/Form.tsx', $contents))->toBe([strpos($contents, marker('place|holder'))]);
});

it('fails the word anywhere else in a .html file', function (string $contents, string $words): void {
    expect(MarkerScan::hitsIn('public/form.html', withHintWord("<form>\n{$contents}\n</form>\n")))->toBe(['public/form.html:2: '.withHintWord($words)]);
})->with([
    'text' => ['<p>A {w} for the list</p>', '{w}'],
    'text that reads like the attribute' => ['<p>Use {w}="Name" here</p>', '{w}'],
    'a comment with the attribute' => ['<!-- <input {w}="Name"> -->', '{w}'],
    'an attribute value' => ['<input title="{w}" {w}="Name">', '{w}'],
    'a script' => ['<script>const a = "<input {w}=\'Name\'>"; input.{w}="b";</script>', '{w}, {w}'],
    'a style' => ['<style>input::{w} { color: red; }</style>', '{w}'],
    'a textarea' => ['<textarea><input {w}="Name"></textarea>', '{w}'],
    'an unquoted value' => ['<input {w}=Name>', '{w}'],
    'the attribute with spaces around =' => ['<input {w} = "Name">', '{w}'],
    'the attribute in upper case' => ['<input {W}="Name">', '{W}'],
    'the attribute in the plural' => ['<input {w}s="Name">', '{w}s'],
    'the attribute value that names the attribute' => ['<input {w}="{w}">', '{w}'],
]);
