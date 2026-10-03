<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Node;
use Cbox\Cms\Tests\Support\Phpstan;
use Symfony\Component\Process\Process;

/*
 * The JS side of gates 1 and 4 in GUARDRAILS 10: tsc with the flags from GUARDRAILS 1, ESLint
 * with type information and Prettier, all on the shared configuration in the js/tooling
 * workspace. These tests guard the configuration itself (GUARDRAILS 7.3): they read the
 * effective configuration from the tools and run probe files that must fail.
 */

/**
 * The rules from GUARDRAILS 1: no explicit any and the rules against unsafe use of any.
 *
 * @return list<string>
 */
function typeSafetyRules(): array
{
    return [
        '@typescript-eslint/no-explicit-any',
        '@typescript-eslint/no-unsafe-argument',
        '@typescript-eslint/no-unsafe-assignment',
        '@typescript-eslint/no-unsafe-call',
        '@typescript-eslint/no-unsafe-declaration-merging',
        '@typescript-eslint/no-unsafe-enum-comparison',
        '@typescript-eslint/no-unsafe-function-type',
        '@typescript-eslint/no-unsafe-member-access',
        '@typescript-eslint/no-unsafe-return',
        '@typescript-eslint/no-unsafe-unary-minus',
    ];
}

/**
 * The severity ESLint resolved for a rule: 0 off, 1 warn, 2 error, null when the rule is absent.
 *
 * @param  array<string, mixed>  $config
 */
function eslintSeverity(array $config, string $rule): ?int
{
    $rules = $config['rules'] ?? null;
    $entry = is_array($rules) ? ($rules[$rule] ?? null) : null;
    $severity = is_array($entry) ? ($entry[0] ?? null) : $entry;

    return is_int($severity) ? $severity : null;
}

function lintProbe(string $path): Process
{
    return Node::tool('eslint', ['--max-warnings=0', '--no-warn-ignored', $path]);
}

it('declares a private root package with npm workspaces for js/* and the gate scripts', function (): void {
    $package = Node::jsonFile('package.json');

    expect($package['private'] ?? null)->toBeTrue()
        ->and($package['workspaces'] ?? null)->toBe(['js/*'])
        ->and($package['scripts'] ?? null)->toBeArray()->toMatchArray([
            // The Browser suite's test modules have a project of their own (B1-X1): React Aria's
            // declarations do not compile with exactOptionalPropertyTypes, which only it turns off.
            'typecheck' => 'tsc --noEmit && tsc --noEmit -p tests/Browser/Fixtures/PanelModules',
            'lint' => 'eslint --max-warnings=0 .',
            'format:check' => 'prettier --check .',
        ]);
});

it('locks the dependencies and links js/tooling as a workspace in the committed lock file', function (): void {
    $package = Node::jsonFile('package.json');
    $tooling = Node::jsonFile('js/tooling/package.json');
    $lock = Node::jsonFile('package-lock.json');
    $packages = $lock['packages'] ?? null;

    expect($lock['lockfileVersion'] ?? null)->toBe(3)
        ->and($packages)->toBeArray()
        ->and(is_array($packages) ? ($packages['node_modules/@cboxdk/cms-tooling'] ?? null) : null)
        ->toBe(['resolved' => 'js/tooling', 'link' => true]);

    foreach ([$package['devDependencies'] ?? null, $tooling['dependencies'] ?? null, $tooling['peerDependencies'] ?? null] as $dependencies) {
        expect($dependencies)->toBeArray();
        expect($dependencies)->not->toBeEmpty();

        foreach (is_array($dependencies) ? $dependencies : [] as $name => $version) {
            expect($version)->toBeString()->toMatch('/^\d+\.\d+\.\d+$/', "{$name} is not pinned to an exact version");
        }
    }
});

it('ships the shared configuration as the js/tooling workspace package', function (): void {
    $tooling = Node::jsonFile('js/tooling/package.json');
    $exports = $tooling['exports'] ?? null;

    expect($tooling['name'] ?? null)->toBe('@cboxdk/cms-tooling')
        ->and($exports)->toBe([
            './tsconfig.base.json' => './tsconfig.base.json',
            './eslint' => './eslint.js',
            './prettier' => './prettier.js',
        ])
        ->and($tooling['peerDependencies'] ?? null)->toBeArray()->toHaveKeys(['eslint', 'prettier', 'typescript'])
        ->and($tooling['dependencies'] ?? null)->toBeArray()
        ->toHaveKeys(['@eslint/js', 'typescript-eslint', 'eslint-plugin-react-hooks']);

    foreach (is_array($exports) ? $exports : [] as $file) {
        expect(Phpstan::root().'/js/tooling/'.(is_string($file) ? $file : ''))->toBeFile();
    }
});

it('turns on the strict flags in js/tooling, and the root tsconfig inherits them', function (): void {
    $root = Node::jsonFile('tsconfig.json');
    $base = Node::jsonFile('js/tooling/tsconfig.base.json');
    $effective = Node::json('tsc', ['--showConfig']);
    $flags = ['strict', 'noUncheckedIndexedAccess', 'exactOptionalPropertyTypes'];
    $rootOptions = $root['compilerOptions'] ?? null;

    expect($root['extends'] ?? null)->toBe('@cboxdk/cms-tooling/tsconfig.base.json')
        ->and($rootOptions)->toBeArray();
    expect($rootOptions)->not->toHaveKeys($flags);
    expect($base['compilerOptions'] ?? null)->toBeArray()->toMatchArray(array_fill_keys($flags, true));
    expect($effective['compilerOptions'] ?? null)->toBeArray()->toMatchArray(array_fill_keys($flags, true));
});

it('type checks the workbench TypeScript and the js/* workspaces', function (): void {
    $root = Node::jsonFile('tsconfig.json');
    $effective = Node::json('tsc', ['--showConfig']);

    expect($root['include'] ?? null)->toBeArray()->toContain('js/*/src/**/*', 'workbench/resources/js/**/*')
        ->and($effective['files'] ?? null)->toBeArray()->toContain(
            './js/tooling/eslint.js',
            './js/tooling/prettier.js',
            './workbench/resources/js/cms/generated/index.ts',
        );
});

it('points the root ESLint and Prettier configuration at js/tooling instead of repeating it', function (): void {
    $eslint = (string) file_get_contents(Phpstan::root().'/eslint.config.js');
    $prettier = (string) file_get_contents(Phpstan::root().'/.prettierrc');

    expect($eslint)->toContain("from '@cboxdk/cms-tooling/eslint'");
    expect($eslint)->not->toMatch('/\brules\s*:/');
    expect($eslint)->not->toContain('@typescript-eslint/');
    expect(trim($prettier))->toBe('"@cboxdk/cms-tooling/prettier"');
});

it('lints TypeScript with type information, strictTypeChecked, the unsafe rules and the React Hooks rules as errors', function (): void {
    $config = Node::json('eslint', ['--print-config', 'workbench/resources/js/cms/generated/index.ts']);
    $languageOptions = $config['languageOptions'] ?? null;
    $parserOptions = is_array($languageOptions) ? ($languageOptions['parserOptions'] ?? null) : null;

    expect($parserOptions)->toBeArray()->toMatchArray([
        'projectService' => true,
        'tsconfigRootDir' => Phpstan::root(),
    ]);

    // Rules that only strictTypeChecked turns on, next to the ones GUARDRAILS 1 names.
    $rules = [
        ...typeSafetyRules(),
        '@typescript-eslint/no-unnecessary-condition',
        '@typescript-eslint/restrict-template-expressions',
        '@typescript-eslint/no-floating-promises',
        'react-hooks/rules-of-hooks',
        'react-hooks/exhaustive-deps',
    ];

    foreach ($rules as $rule) {
        expect(eslintSeverity($config, $rule))->toBe(2, "{$rule} is not an error");
    }
});

it('fails the lint on an explicit any, on unsafe use of any and on a conditional hook', function (string $code, string $rule): void {
    $process = Node::withProbe('ts', $code, lintProbe(...));

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain($rule);
})->with([
    'explicit any' => ["export const value: any = 1;\n", '@typescript-eslint/no-explicit-any'],
    'unsafe member access' => ["export const value: unknown = JSON.parse('{}').name;\n", '@typescript-eslint/no-unsafe-member-access'],
    'conditional hook' => [
        "function useCount(): number {\n  return 1;\n}\n\nexport function Counter(props: { on: boolean }): number {\n  if (props.on) {\n    return useCount();\n  }\n\n  return 0;\n}\n",
        'react-hooks/rules-of-hooks',
    ],
]);

it('fails the type check on an unchecked index and on undefined in an optional property', function (string $code, string $error): void {
    $process = Node::withProbe('ts', $code, static fn (): Process => Node::run(['npm', 'run', '--silent', 'typecheck']));

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->toContain($error);
})->with([
    'noUncheckedIndexedAccess' => [
        "const values: number[] = [1];\n\nexport const first: number = values[0];\n",
        "error TS2322: Type 'number | undefined' is not assignable to type 'number'.",
    ],
    'exactOptionalPropertyTypes' => [
        "type Options = { label?: string };\n\nexport const options: Options = { label: undefined };\n",
        "with 'exactOptionalPropertyTypes: true'",
    ],
]);

it('fails the format check on code that Prettier would change', function (): void {
    $process = Node::withProbe(
        'ts',
        "export const label = \"double quotes\";\n",
        static fn (string $path): Process => Node::tool('prettier', ['--check', $path]),
    );

    expect($process->getExitCode())->toBe(1);
});

it('passes lint, type check and format check on a probe that checks its index', function (): void {
    $code = "const values: number[] = [1];\n\nexport const first: number = values[0] ?? 0;\n";

    [$lint, $typecheck, $format] = Node::withProbe('ts', $code, static fn (string $path): array => [
        lintProbe($path),
        Node::run(['npm', 'run', '--silent', 'typecheck']),
        Node::tool('prettier', ['--check', $path]),
    ]);

    expect($lint->getExitCode())->toBe(0, $lint->getOutput())
        ->and($typecheck->getExitCode())->toBe(0, $typecheck->getOutput())
        ->and($format->getExitCode())->toBe(0, $format->getOutput());
});
