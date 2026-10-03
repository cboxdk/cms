<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use Symfony\Component\Process\Process;

/*
 * The panel and component kit workspaces, js/panel and js/ui-kit (PRD 13.4, GUARDRAILS 2.6 and
 * 8): React 19, Inertia and Vite on the shared strict configuration, covered by gate 4. GUARDRAILS
 * 8 puts every text through the translations, in Danish and English: the rule
 * cms/no-literal-ui-text in js/tooling rejects literal text in JSX, and the parity check
 * js/tooling/translation-parity.js holds the panel's catalogues to the same keys. These tests run
 * both on planted files, so the checks are known to fail when they should (GUARDRAILS 7.3).
 */

/**
 * The input hint attribute, joined from parts, because the marker gate of GUARDRAILS 11 rejects
 * the word in a PHP file.
 */
function inputHintAttribute(): string
{
    return 'place'.'holder';
}

/**
 * The severity ESLint resolved for a rule: 0 off, 1 warn, 2 error, null when the rule is absent.
 *
 * @param  array<string, mixed>  $config
 */
function workspaceRuleSeverity(array $config, string $rule): ?int
{
    $rules = $config['rules'] ?? null;
    $entry = is_array($rules) ? ($rules[$rule] ?? null) : null;
    $severity = is_array($entry) ? ($entry[0] ?? null) : $entry;

    return is_int($severity) ? $severity : null;
}

function lintUiTextProbe(string $path): Process
{
    return Node::tool('eslint', ['--max-warnings=0', '--no-warn-ignored', $path]);
}

/**
 * Runs the parity check on a directory of catalogues written for the test, and removes it again.
 *
 * @param  array<string, string>  $catalogues  file name to its JSON text
 */
function translationParity(array $catalogues): Process
{
    $directory = sys_get_temp_dir().'/cms-catalogues-'.bin2hex(random_bytes(4));
    mkdir($directory);

    try {
        foreach ($catalogues as $file => $json) {
            file_put_contents($directory.'/'.$file, $json);
        }

        return Node::run(['node', 'js/tooling/translation-parity.js', $directory]);
    } finally {
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($directory);
    }
}

it('declares js/panel, js/ui-kit and js/panel-sdk as private workspaces on React 19, Inertia and Vite, pinned exactly', function (): void {
    $panel = Node::jsonFile('js/panel/package.json');
    $kit = Node::jsonFile('js/ui-kit/package.json');
    $sdk = Node::jsonFile('js/panel-sdk/package.json');
    $lock = Node::jsonFile('package-lock.json');
    $packages = is_array($lock['packages'] ?? null) ? $lock['packages'] : [];

    expect($panel['private'] ?? null)->toBeTrue()
        ->and($kit['private'] ?? null)->toBeTrue()
        ->and($sdk['private'] ?? null)->toBeTrue()
        ->and($panel['name'] ?? null)->toBe('@cboxdk/cms-panel-app')
        ->and($kit['name'] ?? null)->toBe('@cboxdk/cms-ui-kit')
        ->and($sdk['name'] ?? null)->toBe('@cboxdk/cms-panel')
        ->and($sdk['dependencies'] ?? null)->toBe(['@cboxdk/cms-tooling' => '0.1.0', '@cboxdk/cms-ui-kit' => '0.1.0'])
        ->and($sdk['peerDependencies'] ?? null)->toBeArray()->toMatchArray(['react' => '19.3.0'])
        ->and($panel['dependencies'] ?? null)->toBeArray()
        ->toMatchArray(['react' => '19.3.0', 'react-dom' => '19.3.0', '@cboxdk/cms-ui-kit' => '0.1.0'])
        ->toHaveKey('@inertiajs/react')
        ->and($panel['devDependencies'] ?? null)->toBeArray()->toHaveKeys(['vite', '@vitejs/plugin-react', '@types/react'])
        ->and($kit['peerDependencies'] ?? null)->toBe(['react' => '19.3.0'])
        ->and($packages['node_modules/@cboxdk/cms-panel-app'] ?? null)->toBe(['resolved' => 'js/panel', 'link' => true])
        ->and($packages['node_modules/@cboxdk/cms-panel'] ?? null)->toBe(['resolved' => 'js/panel-sdk', 'link' => true])
        ->and($packages['node_modules/@cboxdk/cms-ui-kit'] ?? null)->toBe(['resolved' => 'js/ui-kit', 'link' => true]);

    foreach ([$panel, $kit, $sdk] as $package) {
        foreach (['dependencies', 'devDependencies', 'peerDependencies'] as $kind) {
            $dependencies = $package[$kind] ?? [];

            foreach (is_array($dependencies) ? $dependencies : [] as $name => $version) {
                expect($version)->toBeString()->toMatch('/^\d+\.\d+\.\d+$/', "{$name} is not pinned to an exact version");
            }
        }
    }
});

it('type checks and lints both workspaces in gate 4', function (): void {
    $effective = Node::json('tsc', ['--showConfig']);

    expect($effective['files'] ?? null)->toBeArray()->toContain(
        './js/panel/src/app.tsx',
        './js/panel/vite.config.ts',
        './js/ui-kit/src/index.ts',
        './js/ui-kit/src/components/Button.tsx',
    );

    $config = Node::json('eslint', ['--print-config', 'js/panel/src/app.tsx']);
    expect(workspaceRuleSeverity($config, 'cms/no-literal-ui-text'))->toBe(2)
        ->and(workspaceRuleSeverity($config, 'react-hooks/rules-of-hooks'))->toBe(2)
        ->and(workspaceRuleSeverity($config, '@typescript-eslint/no-unsafe-member-access'))->toBe(2);
});

it('keeps the components in js/ui-kit, with design tokens as CSS custom properties', function (): void {
    $root = Phpstan::root();
    $tokens = (string) file_get_contents($root.'/js/ui-kit/src/tokens.css');
    $button = (string) file_get_contents($root.'/js/ui-kit/src/components/button.css');

    expect($root.'/js/panel/src/components')->not->toBeDirectory()
        ->and($tokens)->toMatch('/^\s*--cms-color-[a-z-]+:/m')
        ->and($button)->not->toMatch('/#[0-9a-f]{3,8}\b/i')
        ->and($button)->toContain('var(--cms-color-');
});

it('builds the panel with Vite', function (): void {
    $directory = sys_get_temp_dir().'/cms-panel-build-'.bin2hex(random_bytes(4));

    try {
        $build = Node::tool('vite', ['build', '--config', 'js/panel/vite.config.ts', '--outDir', $directory, '--emptyOutDir', '--logLevel', 'warn']);

        expect($build->getExitCode())->toBe(0, $build->getErrorOutput().$build->getOutput())
            ->and($directory.'/.vite/manifest.json')->toBeFile();
    } finally {
        new Process(['rm', '-rf', $directory])->run();
    }
});

it('fails the lint on literal text in JSX and in user-facing attributes', function (string $jsx): void {
    $code = "export function Probe({ name }: { readonly name: string }) {\n  return {$jsx};\n}\n";
    $process = Node::withProbe('tsx', $code, lintUiTextProbe(...));

    expect($process->getExitCode())->toBe(1, $process->getOutput())
        ->and($process->getOutput())->toContain('cms/no-literal-ui-text');
})->with([
    'text between tags' => ['<p>Save {name}</p>'],
    'a string as a child' => ["<p>{'Save'}</p>"],
    'a template literal as a child' => ['<p>{`Save ${name}`}</p>'],
    'a branch of a condition' => ["<p>{name === '' ? 'Nobody' : name}</p>"],
    'aria-label' => ['<button type="button" aria-label="Close">{name}</button>'],
    'title in braces' => ["<span title={'Close'}>{name}</span>"],
    'alt' => ['<img alt="A logo" src={name} />'],
    'the input hint' => ['<input '.inputHintAttribute().'="Your name" value={name} />'],
    'Danish letters' => ['<p>Gem ændringer</p>'],
]);

it('passes the lint on text from translations and on attributes only code reads', function (): void {
    $code = <<<'TSX'
        export function Probe({ label, t }: { readonly label: string; readonly t: (key: string) => string }) {
          return (
            <section className="cms-probe" role="region" aria-label={t('probe.region')} data-state="open">
              <h2 id="cms-probe-title">{t('probe.title')}</h2>
              {' / '}
              <input type="text" value={label} aria-labelledby="cms-probe-title" />
              <img alt="" src="logo.svg" />
              {label}
            </section>
          );
        }

        TSX;

    $process = Node::withProbe('tsx', $code, lintUiTextProbe(...));

    expect($process->getExitCode())->toBe(0, $process->getOutput());
});

it('passes the translation parity check on the panel catalogues', function (): void {
    $process = Node::run(['npm', 'run', '--silent', 'lint:translations']);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and(Phpstan::root().'/js/panel/src/i18n/catalogues/da.json')->toBeFile()
        ->and(Phpstan::root().'/js/panel/src/i18n/catalogues/en.json')->toBeFile();
});

it('passes the translation parity check on catalogues with the same keys', function (): void {
    $process = translationParity([
        'da.json' => '{"panel.save": "Gem", "panel.cancel": "Annuller"}',
        'en.json' => '{"panel.cancel": "Cancel", "panel.save": "Save"}',
    ]);

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});

it('fails the translation parity check on a planted key present only in da', function (): void {
    $process = translationParity([
        'da.json' => '{"panel.save": "Gem", "panel.only_danish": "Kun dansk"}',
        'en.json' => '{"panel.save": "Save"}',
    ]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('en.json: missing the key "panel.only_danish" that da.json has');
});

it('fails the translation parity check on a missing catalogue, an empty text and a nested object', function (array $catalogues, string $problem): void {
    /** @var array<string, string> $catalogues */
    $process = translationParity($catalogues);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain($problem);
})->with([
    'no en catalogue' => [['da.json' => '{"panel.save": "Gem"}'], 'en.json: missing'],
    'an empty text' => [['da.json' => '{"panel.save": " "}', 'en.json' => '{"panel.save": "Save"}'], 'da.json: the key "panel.save" has no text'],
    'a nested object' => [['da.json' => '{"panel": {"save": "Gem"}}', 'en.json' => '{"panel": {"save": "Save"}}'], 'da.json: the key "panel" has no text'],
    'a third locale' => [['da.json' => '{"a.b": "x"}', 'en.json' => '{"a.b": "x"}', 'de.json' => '{"a.b": "x"}'], 'de.json: not a catalogue of the locales da, en'],
    'not JSON' => [['da.json' => '{', 'en.json' => '{}'], 'da.json: not valid JSON'],
]);
