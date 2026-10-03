<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use Symfony\Component\Process\Process;

/*
 * The foundations of the component kit, js/ui-kit (GUARDRAILS 8, the panel extension architecture
 * of 2 October 2026, sections 2.2 to 2.6): the kit's own tests (`npm run test:kit`: the contrast
 * pairs of the design tokens in both modes, the cascade layers, the part hooks and the kit's
 * catalogues) run in gate 5 here, and the lint rules that hold the kit run on planted files, so
 * each check is known to fail when it should (GUARDRAILS 7.3).
 */

const KIT_SOURCE = 'js/ui-kit/src';

/**
 * Runs a command of the kit from the root without the toolchain lock (tests/Support/
 * JsToolchainLock.php). The lock keeps tsc and ESLint from seeing another process's probe; the
 * kit's own tests, the parity check and ESLint's printed configuration read no probe, and taking
 * the lock for them would only make a test that waits for the exclusive lock wait longer.
 *
 * @param  list<string>  $command
 * @param  array<string, string>  $environment
 */
function kitProcess(array $command, array $environment = []): Process
{
    $process = new Process($command, Phpstan::root(), $environment === [] ? null : $environment, null, 180);
    $process->run();

    return $process;
}

/**
 * Writes a copy of tokens.json with one value replaced, runs the token tests against it, and
 * removes it again.
 */
function kitTokensWith(string $search, string $replace): Process
{
    $tokens = (string) file_get_contents(Phpstan::root().'/js/ui-kit/tokens.json');
    $planted = str_replace($search, $replace, $tokens);
    expect($planted)->not->toBe($tokens, "{$search} is not in tokens.json");
    $path = sys_get_temp_dir().'/cms-kit-tokens-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($path, $planted);

    try {
        return kitProcess(['node', 'js/ui-kit/tests/run.js', 'tokens'], ['CMS_KIT_TOKENS' => $path]);
    } finally {
        unlink($path);
    }
}

function lintKitProbe(string $directory, string $code): Process
{
    return Node::withProbeIn($directory, 'tsx', $code, static fn (string $path): Process => Node::tool('eslint', ['--max-warnings=0', '--no-warn-ignored', $path]));
}

/**
 * The severity ESLint resolved for a rule on a file: 0 off, 1 warn, 2 error, null when absent.
 */
function kitRuleSeverity(string $file, string $rule): ?int
{
    $process = kitProcess([Phpstan::root().'/node_modules/.bin/eslint', '--print-config', $file]);
    $config = json_decode($process->getOutput(), true);
    $rules = is_array($config) ? ($config['rules'] ?? null) : null;
    $entry = is_array($rules) ? ($rules[$rule] ?? null) : null;
    $severity = is_array($entry) ? ($entry[0] ?? null) : $entry;

    return is_int($severity) ? $severity : null;
}

it('passes the kit\'s own tests', function (): void {
    $process = kitProcess(['npm', 'run', '--silent', 'test:kit']);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toContain('reaches 4.50:1 in the dark mode', 'reaches 3.00:1 in the light mode', 'layers.css');
});

it('runs one file of the kit\'s tests by name, and refuses a name without one', function (): void {
    $tokens = kitProcess(['npm', 'run', '--silent', 'test:kit', '--', 'tokens']);
    $unknown = kitProcess(['npm', 'run', '--silent', 'test:kit', '--', 'colours']);

    expect($tokens->getExitCode())->toBe(0, $tokens->getOutput())
        ->and($tokens->getOutput())->toContain('color-text on color-surface (text) reaches 4.50:1')
        ->and($tokens->getOutput())->not->toContain('layers.css')
        ->and($unknown->getExitCode())->toBe(64)
        ->and($unknown->getErrorOutput())->toContain('No kit test named colours', 'tokens');
});

it('fails the token tests when a listed contrast pair drops below its minimum', function (string $search, string $replace, string $failure): void {
    $process = kitTokensWith($search, $replace);

    expect($process->getExitCode())->toBe(1, $process->getOutput())
        ->and($process->getOutput())->toContain($failure);
})->with([
    'muted text below 4.5:1 in the dark mode' => [
        '"value": "oklch(70% 0.012 250)"',
        '"value": "oklch(42% 0.012 250)"',
        'color-text-muted on color-surface is 2.31:1 in the dark mode, below 4.50:1',
    ],
    'the border of a control below 3:1 in the light mode' => [
        '"value": "oklch(64% 0.012 250)"',
        '"value": "oklch(80% 0.012 250)"',
        'color-border-strong on color-surface is 1.82:1 in the light mode, below 3.00:1',
    ],
    'the focus ring below 3:1 in the light mode' => [
        '"value": "oklch(45% 0.16 258)"',
        '"value": "oklch(85% 0.06 258)"',
        'color-focus on color-surface is 1.54:1 in the light mode, below 3.00:1',
    ],
]);

it('holds the kit to its lint rules, and its consumers to the rule against React Aria', function (): void {
    expect(kitRuleSeverity(KIT_SOURCE.'/components/Button.tsx', 'cms-kit/no-style-props'))->toBe(2)
        ->and(kitRuleSeverity(KIT_SOURCE.'/components/Button.tsx', 'cms/no-literal-ui-text'))->toBe(2)
        ->and(kitRuleSeverity(KIT_SOURCE.'/index.ts', 'no-restricted-imports'))->toBe(2)
        ->and(kitRuleSeverity('js/panel/src/app.tsx', 'no-restricted-imports'))->toBe(2)
        ->and(kitRuleSeverity('js/panel/src/app.tsx', 'cms-kit/no-style-props'))->toBeNull();
});

/*
 * Each ESLint run with type information takes seconds and holds the toolchain lock, so the
 * violations share one probe file and are each named in the output, rather than one run each.
 */
it('fails the lint on kit components that accept className or style, on literal text and on React Aria from the package root', function (): void {
    $process = lintKitProbe(KIT_SOURCE.'/components', <<<'TSX'
        import { memo, type HTMLAttributes } from 'react';
        import { Dialog } from 'react-aria-components';

        export interface ExtendsProps extends HTMLAttributes<HTMLDivElement> {
          readonly tone?: 'info';
        }

        export function Extends(props: ExtendsProps) {
          return <div {...props} />;
        }

        export function OwnStyle({ style }: { readonly style?: string }) {
          return <div data-style={style} />;
        }

        export const Memoised = memo(function Inner({ className }: { readonly className?: string }) {
          return <div data-class={className} />;
        });

        const Local = ({ className }: { readonly className?: string }) => <div data-class={className} />;

        export { Local as ByName };

        export function Literal() {
          return <button type="button">Close</button>;
        }

        export const primitive = Dialog;

        TSX);

    expect($process->getExitCode())->toBe(1, $process->getOutput())
        ->and($process->getOutput())->toContain(
            'The kit component "Extends" accepts the prop "className"',
            'The kit component "Extends" accepts the prop "style"',
            'The kit component "OwnStyle" accepts the prop "style"',
            'The kit component "Memoised" accepts the prop "className"',
            'The kit component "ByName" accepts the prop "className"',
            'cms-kit/no-style-props',
            'cms/no-literal-ui-text',
            'Import each primitive from its own module',
        )
        ->and(substr_count($process->getOutput(), 'cms-kit/no-style-props'))->toBe(5);
});

it('passes the lint on a kit component that leaves className and style out and imports React Aria by component', function (): void {
    $process = lintKitProbe(KIT_SOURCE.'/components', <<<'TSX'
        import type { HTMLAttributes } from 'react';
        import { Dialog } from 'react-aria-components/Dialog';

        export interface ProbeProps extends Omit<HTMLAttributes<HTMLDivElement>, 'className' | 'style'> {
          readonly tone?: 'info';
        }

        export function Probe({ tone = 'info', ...rest }: ProbeProps) {
          return <div {...rest} data-tone={tone} />;
        }

        function helper(className: string): string {
          return className;
        }

        export const value = helper('cms-probe');

        export const primitive = Dialog;

        TSX);

    expect($process->getExitCode())->toBe(0, $process->getOutput());
});

it('fails the lint on React Aria imported from the panel', function (): void {
    $process = lintKitProbe('js/panel/src', "import { Dialog } from 'react-aria-components/Dialog';\n\nexport const primitive = Dialog;\n");

    expect($process->getExitCode())->toBe(1, $process->getOutput())
        ->and($process->getOutput())->toContain('no-restricted-imports', 'React Aria is internal to the component kit');
});

it('passes the translation parity check on the kit catalogue in da and en, through lint:translations', function (): void {
    $script = Node::jsonFile('package.json')['scripts'] ?? [];
    $parity = kitProcess(['node', 'js/tooling/translation-parity.js', 'js/ui-kit/src/i18n/catalogues']);
    $translations = kitProcess(['npm', 'run', '--silent', 'lint:translations']);

    expect(is_array($script) ? ($script['lint:translations'] ?? null) : null)->toBeString()->toContain('js/ui-kit/src/i18n/catalogues')
        ->and($parity->getExitCode())->toBe(0, $parity->getErrorOutput())
        ->and($translations->getExitCode())->toBe(0, $translations->getErrorOutput())
        ->and(Phpstan::root().'/js/ui-kit/src/i18n/catalogues/da.json')->toBeFile()
        ->and(Phpstan::root().'/js/ui-kit/src/i18n/catalogues/en.json')->toBeFile();
});

it('fails the kit\'s catalogue test on a key present only in da', function (): void {
    $directory = sys_get_temp_dir().'/cms-kit-catalogues-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $catalogues = Phpstan::root().'/js/ui-kit/src/i18n/catalogues';
    file_put_contents($directory.'/en.json', (string) file_get_contents($catalogues.'/en.json'));
    // Only the catalogue's opening brace: a text may name a value in braces, such as {count}.
    file_put_contents($directory.'/da.json', (string) preg_replace('/^\{/', "{\n  \"kit.only_danish\": \"Kun dansk\",", (string) file_get_contents($catalogues.'/da.json'), 1));

    try {
        $process = kitProcess(['node', 'js/ui-kit/tests/run.js', 'i18n'], ['CMS_KIT_CATALOGUES' => $directory]);
    } finally {
        unlink($directory.'/en.json');
        unlink($directory.'/da.json');
        rmdir($directory);
    }

    expect($process->getExitCode())->toBe(1, $process->getOutput())
        ->and($process->getOutput())->toContain('en.json: missing the key "kit.only_danish" that da.json has');
});
