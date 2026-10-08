<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling\Docs;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Attributes\Stable;
use Cbox\Cms\Contracts\Idempotency\ClaimResult;
use Cbox\Cms\Testkit\Postgres\RealPostgres;
use Cbox\Cms\Testkit\Valkey\RealValkey;
use Cbox\Cms\Tests\Support\Phpstan;
use Cbox\Cms\Tests\Support\Tooling\ComposerScripts;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use Cbox\Cms\Tooling\Docs\Boundary\DocsCheckOptions;
use Cbox\Cms\Tooling\Docs\Boundary\LocalDocsTree;
use Cbox\Cms\Tooling\Docs\Boundary\PhpTokens;
use Cbox\Cms\Tooling\Docs\Boundary\VitestUnitSuite;
use Cbox\Cms\Tooling\Docs\Domain\BrowserScreenshot;
use Cbox\Cms\Tooling\Docs\Domain\CapturedImage;
use Cbox\Cms\Tooling\Docs\Domain\DeclaredType;
use Cbox\Cms\Tooling\Docs\Domain\DocsAudit;
use Cbox\Cms\Tooling\Docs\Domain\Exclusion;
use Cbox\Cms\Tooling\Docs\Domain\Exclusions;
use Cbox\Cms\Tooling\Docs\Domain\Finding;
use Cbox\Cms\Tooling\Docs\Domain\Inventory;
use Cbox\Cms\Tooling\Docs\Domain\JsSuite;
use Cbox\Cms\Tooling\Docs\Domain\Marker;
use Cbox\Cms\Tooling\Docs\Domain\MarkerKind;
use Cbox\Cms\Tooling\Docs\Domain\PageParser;
use Cbox\Cms\Tooling\Docs\Domain\Screenshot;
use Cbox\Cms\Tooling\Docs\Domain\Screenshots;
use Cbox\Cms\Tooling\Docs\Domain\TypeKind;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Process\Process;

/*
 * Gate 10 of GUARDRAILS 10, `composer docs:check` (tools/bin/docs-check.php): every public
 * extension point has one page with a running example, and the code on the pages is the tested
 * code, byte for byte (GUARDRAILS 2.4, PRD 14.4). Each finding has a case on a fixture tree in a
 * scratch directory that starts from a tree with no finding: one interface, its page and its
 * example in the Unit suite. The script itself runs on a scratch copy of this repository.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

const DOCS_GREETER = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Cbox\Cms\Contracts;

    use Cbox\Cms\Contracts\Attributes\Experimental;

    #[Experimental]
    interface Greeter
    {
        public function greet(string $name): string;
    }

    PHP;

const DOCS_GREETER_TEST = <<<'PHP'
    <?php

    declare(strict_types=1);

    it('greets by name', function (): void {
        expect('Hello, Ada')->toBe('Hello, Ada');
    });

    PHP;

const DOCS_PHPUNIT = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <phpunit>
        <testsuites>
            <testsuite name="Unit">
                <directory suffix="Test.php">examples/Unit</directory>
            </testsuite>
            <testsuite name="Browser">
                <directory suffix="Test.php">examples/Browser</directory>
            </testsuite>
        </testsuites>
    </phpunit>

    XML;

const DOCS_VITEST = <<<'TS'
    import { defineConfig } from 'vitest/config';

    export default defineConfig({
      test: {
        projects: [
          {
            test: {
              name: 'unit',
              include: ['js/*/tests/**/*.test.{js,ts,tsx}', 'examples/Vitest/**/*.test.{ts,tsx}'],
              environment: 'node',
            },
          },
          {
            test: {
              name: 'storybook',
              include: ['js/ui-kit/stories/**/*.stories.tsx'],
            },
          },
        ],
      },
    });

    TS;

const DOCS_GREETER_SPEC = <<<'TS'
    import { expect, test } from 'vitest';

    test('greets by name', () => {
      expect('Hello, Ada').toBe('Hello, Ada');
    });

    TS;

const DOCS_GREETER_PAGE = 'docs/addons/greeter.md';

const DOCS_GREETER_EXAMPLE = 'examples/Unit/Greeting/GreeterTest.php';

/**
 * A marker with the fenced block of the contents below it.
 */
function docsEmbed(string $kind, string $path, string $contents, string $language = 'php'): string
{
    return "<!-- {$kind}: {$path} -->\n```{$language}\n{$contents}```\n";
}

/**
 * The frontmatter of a page, six lines with the empty line after it.
 */
function docsFrontmatter(string $title, int $weight): string
{
    return "---\ntitle: {$title}\nweight: {$weight}\ndescription: What the page is about.\n---\n\n";
}

/**
 * A page below docs/addons that documents the extension points and embeds the blocks. The marker
 * is on line 9, after the frontmatter.
 */
function docsPage(string $extensionPoint, string ...$blocks): string
{
    return docsFrontmatter('A page', 31)."# A page\n\n<!-- extension-point: {$extensionPoint} -->\n\nWhat it is for, and `composer docs:check` in inline code.\n\n".implode("\n", $blocks);
}

/**
 * A fixture tree with no finding: the interface Cbox\Cms\Contracts\Greeter, its page and its example
 * test in the Unit suite, and the docs/ layout around the page: the three root pages and the
 * section page of docs/addons.
 */
function docsTree(): string
{
    $root = ScratchDirectory::make('cbox-cms-docs-test-');

    docsWrite($root, 'phpunit.xml', DOCS_PHPUNIT);
    docsWrite($root, 'README.md', "# Greeter\n\nSee [the docs](docs/index.md).\n");
    docsWrite($root, 'CONTRIBUTING.md', "# Contributing\n\nRead [the quickstart](docs/quickstart.md).\n");
    docsWrite($root, 'SECURITY.md', "# Security\n\nSee [the addons](docs/addons/).\n");
    docsWrite($root, 'LICENSE', "MIT License\n");
    docsWrite($root, 'docs/index.md', docsFrontmatter('Greeter', 1)."# Greeter\n\nSee [the addons](addons/_index.md).\n");
    docsWrite($root, 'docs/quickstart.md', docsFrontmatter('Quickstart', 2)."# Quickstart\n");
    docsWrite($root, 'docs/requirements.md', docsFrontmatter('Requirements', 3)."# Requirements\n");
    docsWrite($root, 'docs/addons/_index.md', docsFrontmatter('Addons', 30)."# Addons\n\n- [Greeter](greeter.md#a-page)\n");
    docsWrite($root, 'packages/contracts/src/Greeter.php', DOCS_GREETER);
    docsWrite($root, DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST);
    docsWrite($root, DOCS_GREETER_PAGE, docsPage('Cbox\Cms\Contracts\Greeter', docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST)));

    return $root;
}

function docsWrite(string $root, string $path, string $contents): void
{
    ScratchDirectory::write($root.'/'.$path, $contents);
}

/**
 * @param  list<Exclusion>  $exclusions
 * @param  list<CapturedImage>  $screenshots
 * @return list<string>
 */
function docsFindings(string $root, array $exclusions = [], array $screenshots = []): array
{
    return array_map(static fn (Finding $finding): string => (string) $finding, DocsAudit::findings(LocalDocsTree::read($root), $exclusions, $screenshots));
}

/**
 * A PHP file with the declaration below the namespace and its imports.
 */
function docsPhp(string $namespace, string $declaration, string $imports = ''): string
{
    return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$imports}{$declaration}\n";
}

/**
 * Runs tools/bin/docs-check.php of this checkout with the arguments.
 *
 * @return array{int, string, string}
 */
function runDocsCheck(string ...$arguments): array
{
    $process = new Process([PHP_BINARY, Phpstan::root().'/tools/bin/docs-check.php', ...array_values($arguments)], Phpstan::root(), null, null, 120);
    $process->run();

    return [$process->getExitCode() ?? -1, $process->getOutput(), $process->getErrorOutput()];
}

it('finds nothing in a tree where the one extension point has its page and a running example', function (): void {
    expect(docsFindings(docsTree()))->toBe([]);
});

it('reports an undocumented interface', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/core/src/Greeting/Domain/Farewell.php', docsPhp('Cbox\Cms\Core\Greeting\Domain', "#[Experimental]\ninterface Farewell {}", "use Cbox\\Cms\\Contracts\\Attributes\\Experimental;\n\n"));

    expect(docsFindings($root))->toBe(['Cbox\Cms\Core\Greeting\Domain\Farewell: undocumented']);
});

it('reports an undocumented attribute class, found by #[Attribute] through its import', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/contracts/src/Attributes/Greets.php', docsPhp('Cbox\Cms\Contracts\Attributes', "#[Attribute(Attribute::TARGET_CLASS)]\n#[Experimental]\nfinal readonly class Greets {}", "use Attribute;\n\n"));

    expect(docsFindings($root))->toBe(['Cbox\Cms\Contracts\Attributes\Greets: undocumented']);
});

it('reports an undocumented trait', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/testkit/src/Greeting/GreeterContract.php', docsPhp('Cbox\Cms\Testkit\Greeting', 'trait GreeterContract {}'));

    expect(docsFindings($root))->toBe(['Cbox\Cms\Testkit\Greeting\GreeterContract: undocumented']);
});

it('reports an undocumented #[Command] class, #[Internal] or not, and an undocumented #[Hook] class', function (): void {
    $root = docsTree();
    $imports = "use Cbox\\Cms\\Contracts\\Attributes as Kernel;\nuse Cbox\\Cms\\Contracts\\Attributes\\{Command, Internal};\n\n";
    docsWrite($root, 'packages/core/src/Greeting/Domain/Commands/SendGreeting.php', docsPhp('Cbox\Cms\Core\Greeting\Domain\Commands', "#[Command('greeting.send', version: 1), Internal]\nfinal readonly class SendGreeting {}", $imports));
    docsWrite($root, 'packages/core/src/Greeting/Hooks/CheckGreeting.php', docsPhp('Cbox\Cms\Core\Greeting\Hooks', "#[Kernel\\Hook(command: 'greeting.send')]\nfinal readonly class CheckGreeting {}", $imports));
    docsWrite($root, 'packages/core/src/Greeting/Domain/Commands/Plain.php', docsPhp('Cbox\Cms\Core\Greeting\Domain\Commands', 'final readonly class Plain {}'));

    expect(docsFindings($root))->toBe([
        'Cbox\Cms\Core\Greeting\Domain\Commands\SendGreeting: undocumented',
        'Cbox\Cms\Core\Greeting\Hooks\CheckGreeting: undocumented',
    ]);
});

it('reports an undocumented #[PanelPoint] class, and leaves an #[Internal] one out', function (): void {
    $root = docsTree();
    $imports = "use Cbox\\Cms\\Contracts\\Attributes\\{Experimental, Internal};\nuse Cbox\\Cms\\Contracts\\PanelPoints\\PanelPoint;\nuse Cbox\\Cms\\Contracts\\PanelPoints as Panel;\n\n";
    docsWrite($root, 'packages/panel/src/Points/GreetingSectionsV1.php', docsPhp('Cbox\Cms\Panel\Points', "#[Experimental]\n#[PanelPoint(name: 'greeting.sections', version: 1, kind: Panel\\PointKind::Slot, page: 'greeting', since: '1.0', label: 'panel.points.greeting', region: Panel\\Region::Sections)]\nfinal readonly class GreetingSectionsV1 {}", $imports));
    docsWrite($root, 'packages/panel/src/Points/GreetingToolbarV1.php', docsPhp('Cbox\Cms\Panel\Points', "#[Panel\\PanelPoint(name: 'greeting.toolbar', version: 1, kind: Panel\\PointKind::Slot, page: 'greeting', since: '1.0', label: 'panel.points.toolbar', region: Panel\\Region::Toolbar)]\n#[Experimental]\nfinal readonly class GreetingToolbarV1 {}", $imports));
    docsWrite($root, 'packages/panel/src/Points/GreetingWiringV1.php', docsPhp('Cbox\Cms\Panel\Points', "#[Internal]\n#[PanelPoint(name: 'greeting.wiring', version: 1, kind: Panel\\PointKind::Observer, page: 'greeting', since: '1.0', label: 'panel.points.wiring')]\nfinal readonly class GreetingWiringV1 {}", $imports));

    expect(docsFindings($root))->toBe([
        'Cbox\Cms\Panel\Points\GreetingSectionsV1: undocumented',
        'Cbox\Cms\Panel\Points\GreetingToolbarV1: undocumented',
    ]);
});

it('reports an undocumented schema', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/contracts/resources/schemas/greeting.v1.json', "{}\n");

    expect(docsFindings($root))->toBe(['packages/contracts/resources/schemas/greeting.v1.json: undocumented']);
});

it('leaves an #[Internal] interface, trait and attribute class out of the inventory', function (): void {
    $root = docsTree();
    $imports = "use Attribute;\nuse Cbox\\Cms\\Contracts\\Attributes\\Internal;\n\n";
    docsWrite($root, 'packages/core/src/Greeting/Domain/Probe.php', docsPhp('Cbox\Cms\Core\Greeting\Domain', "#[Internal]\ninterface Probe {}", $imports));
    docsWrite($root, 'packages/core/src/Greeting/Domain/Probing.php', docsPhp('Cbox\Cms\Core\Greeting\Domain', "#[\\Cbox\\Cms\\Contracts\\Attributes\\Internal]\ntrait Probing {}"));
    docsWrite($root, 'packages/core/src/Greeting/Domain/Probed.php', docsPhp('Cbox\Cms\Core\Greeting\Domain', "#[Attribute, Internal]\nfinal readonly class Probed {}", $imports));

    expect(docsFindings($root))->toBe([]);
});

it('reports a page that names an unknown extension point, and one that names an excluded one', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/core/src/Greeting/Domain/Salute.php', docsPhp('Cbox\Cms\Core\Greeting\Domain', 'interface Salute {}'));
    docsWrite($root, DOCS_GREETER_PAGE, docsPage('Cbox\Cms\Contracts\Greeter', "<!-- extension-point: Cbox\\Cms\\Contracts\\Gone -->\n<!-- extension-point: Cbox\\Cms\\Core\\Greeting\\Domain\\Salute -->\n", docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST)));

    expect(docsFindings($root, [new Exclusion('Cbox\Cms\Core\Greeting\Domain\Salute', 'a union that nothing implements')]))->toBe([
        'docs/addons/greeter.md:13: names Cbox\Cms\Contracts\Gone as an extension point, but the inventory has no such interface, attribute class, trait, #[Command], #[Hook] or #[PanelPoint] class or schema that is not #[Internal]',
        'docs/addons/greeter.md:14: names Cbox\Cms\Core\Greeting\Domain\Salute as an extension point, but the inventory excludes it: a union that nothing implements',
    ]);
});

it('reports an extension point on two pages', function (): void {
    $root = docsTree();
    docsWrite($root, 'docs/addons/greeting-again.md', docsPage('Cbox\Cms\Contracts\Greeter', docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST)));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeting-again.md:9: Cbox\Cms\Contracts\Greeter is also documented on docs/addons/greeter.md:9; every extension point is on exactly one page',
    ]);
});

it('reports a page without an example', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/core/src/Greeting/Domain/Farewell.php', docsPhp('Cbox\Cms\Core\Greeting\Domain', 'interface Farewell {}'));
    docsWrite($root, 'docs/addons/farewell.md', docsPage('Cbox\Cms\Core\Greeting\Domain\Farewell'));

    expect(docsFindings($root))->toBe([
        'docs/addons/farewell.md:1: the page documents an extension point and has no example; add <!-- example: <repo-relative path> --> with the fenced *Test.php below it',
    ]);
});

it('reports an example block that differs from its file', function (): void {
    $root = docsTree();
    docsWrite($root, DOCS_GREETER_PAGE, docsPage('Cbox\Cms\Contracts\Greeter', docsEmbed('example', DOCS_GREETER_EXAMPLE, str_replace('Ada', 'Grace', DOCS_GREETER_TEST))));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeter.md:13: the fenced block differs from examples/Unit/Greeting/GreeterTest.php; embed the file byte for byte',
    ]);
});

it('reports a missing example file', function (): void {
    $root = docsTree();
    docsWrite($root, DOCS_GREETER_PAGE, docsPage(
        'Cbox\Cms\Contracts\Greeter',
        docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST),
        docsEmbed('example', 'examples/Unit/Greeting/MissingTest.php', DOCS_GREETER_TEST),
    ));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeter.md:24: examples/Unit/Greeting/MissingTest.php does not exist',
    ]);
});

it('reports an example file that no gate-5 suite includes, and one that is no *Test.php', function (): void {
    $root = docsTree();
    docsWrite($root, 'examples/Browser/Greeting/GreeterPageTest.php', DOCS_GREETER_TEST);
    docsWrite($root, 'examples/Unit/Greeting/Greeting.php', DOCS_GREETER_TEST);
    docsWrite($root, DOCS_GREETER_PAGE, docsPage(
        'Cbox\Cms\Contracts\Greeter',
        docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST),
        docsEmbed('example', 'examples/Browser/Greeting/GreeterPageTest.php', DOCS_GREETER_TEST),
        docsEmbed('example', 'examples/Unit/Greeting/Greeting.php', DOCS_GREETER_TEST),
    ));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeter.md:24: examples/Browser/Greeting/GreeterPageTest.php is in none of the gate-5 suites of phpunit.xml (Unit), so it never runs',
        'docs/addons/greeter.md:35: examples/Unit/Greeting/Greeting.php is not a *Test.php, a *.test.ts or a *.test.tsx; embed a support file or fixture with <!-- example-file: <path> -->',
    ]);
});

it('reports an example file without an assertion', function (): void {
    $root = docsTree();
    $test = "<?php\n\ndeclare(strict_types=1);\n\nit('greets by name', function (): void {\n    greet('Ada');\n});\n";
    docsWrite($root, DOCS_GREETER_EXAMPLE, $test);
    docsWrite($root, DOCS_GREETER_PAGE, docsPage('Cbox\Cms\Contracts\Greeter', docsEmbed('example', DOCS_GREETER_EXAMPLE, $test)));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeter.md:13: examples/Unit/Greeting/GreeterTest.php has no assertion: it calls neither expect() nor an assert method and uses no trait of the inventory',
    ]);
});

it('takes a TypeScript example the unit project of vitest.config.ts includes, with expect() or an expect or assert helper as its assertion, and reports one no include names and one without an assertion', function (): void {
    $root = docsTree();
    $helper = "import { expectSlotContract } from '@cboxdk/cms-panel/testing';\nimport { test } from 'vitest';\n\ntest('keeps the slot contract', async () => {\n  await expectSlotContract({ addon, id: 'greeting.card' });\n});\n";
    $silent = "import { test } from 'vitest';\n\ntest('greets by name', () => {\n  greet('Ada');\n});\n";
    docsWrite($root, 'vitest.config.ts', DOCS_VITEST);
    docsWrite($root, 'examples/Vitest/Greeting/greeter.test.ts', DOCS_GREETER_SPEC);
    docsWrite($root, 'examples/Vitest/Greeting/card.test.tsx', $helper);
    docsWrite($root, 'examples/Vitest/Greeting/silent.test.ts', $silent);
    docsWrite($root, 'js/greeting/stories/greeter.test.ts', DOCS_GREETER_SPEC);
    docsWrite($root, DOCS_GREETER_PAGE, docsPage(
        'Cbox\Cms\Contracts\Greeter',
        docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST),
        docsEmbed('example', 'examples/Vitest/Greeting/greeter.test.ts', DOCS_GREETER_SPEC, 'ts'),
        docsEmbed('example', 'examples/Vitest/Greeting/card.test.tsx', $helper, 'tsx'),
        docsEmbed('example', 'examples/Vitest/Greeting/silent.test.ts', $silent, 'ts'),
        docsEmbed('example', 'js/greeting/stories/greeter.test.ts', DOCS_GREETER_SPEC, 'ts'),
    ));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeter.md:43: examples/Vitest/Greeting/silent.test.ts has no assertion: it calls neither expect() nor a function whose name starts with expect or assert',
        'docs/addons/greeter.md:52: js/greeting/stories/greeter.test.ts is in no include of the unit project of vitest.config.ts (js/*/tests/**/*.test.{js,ts,tsx}, examples/Vitest/**/*.test.{ts,tsx}), so it never runs',
    ]);
});

it('reports a TypeScript example in a tree without vitest.config.ts as one that never runs, and a Vitest test file under examples/ that no page embeds', function (): void {
    $root = docsTree();
    docsWrite($root, 'examples/Vitest/Greeting/greeter.test.ts', DOCS_GREETER_SPEC);
    docsWrite($root, 'examples/Vitest/Greeting/orphan.test.tsx', DOCS_GREETER_SPEC);
    docsWrite($root, DOCS_GREETER_PAGE, docsPage(
        'Cbox\Cms\Contracts\Greeter',
        docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST),
        docsEmbed('example', 'examples/Vitest/Greeting/greeter.test.ts', DOCS_GREETER_SPEC, 'ts'),
    ));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeter.md:24: examples/Vitest/Greeting/greeter.test.ts is in no include of the unit project of vitest.config.ts (no include), so it never runs',
        'examples/Vitest/Greeting/orphan.test.tsx:1: no page embeds this example; embed it on the page of what it shows with <!-- example: examples/Vitest/Greeting/orphan.test.tsx -->',
    ]);
});

it('reports a TypeScript example that imports a module an addon cannot import, or the code of js/ by path', function (): void {
    $root = docsTree();
    $spec = "import { expectSlotContract } from '@cboxdk/cms-panel/testing';\nimport { Button } from '@cboxdk/cms-ui-kit';\nimport { router } from '@inertiajs/react';\nimport { runtime } from '../../../js/panel/src/host/runtime';\nimport addon from '../../../workbench/addons/greeting/resources/panel/src/panel';\nimport { expect, test } from 'vitest';\n\ntest('greets', async () => {\n  expect(await expectSlotContract({ addon, id: 'greeting.card' })).toBeDefined();\n});\n";
    docsWrite($root, 'vitest.config.ts', DOCS_VITEST);
    docsWrite($root, 'examples/Vitest/Greeting/card.test.ts', $spec);
    docsWrite($root, DOCS_GREETER_PAGE, docsPage(
        'Cbox\Cms\Contracts\Greeter',
        docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST),
        docsEmbed('example', 'examples/Vitest/Greeting/card.test.ts', $spec, 'ts'),
    ));

    expect(docsFindings($root))->toBe([
        'examples/Vitest/Greeting/card.test.ts:1: the example imports ../../../js/panel/src/host/runtime, which an addon cannot import; a TypeScript example uses @cboxdk/cms-panel and the addon\'s own modules, so it shows only what an addon may use',
        'examples/Vitest/Greeting/card.test.ts:1: the example imports @cboxdk/cms-ui-kit, which an addon cannot import; a TypeScript example uses @cboxdk/cms-panel and the addon\'s own modules, so it shows only what an addon may use',
        'examples/Vitest/Greeting/card.test.ts:1: the example imports @inertiajs/react, which an addon cannot import; a TypeScript example uses @cboxdk/cms-panel and the addon\'s own modules, so it shows only what an addon may use',
    ])
        ->and(JsSuite::refusedImports('examples/Vitest/Panel/page.test.tsx', "const page = await import('react-aria-components');\nexport { x } from '../../../js/ui-kit/src/kit';\nimport '../Panel/local';\n"))->toBe(['react-aria-components', '../../../js/ui-kit/src/kit']);
});

it('counts a TypeScript example as the test that mentions an embedded support file', function (): void {
    $root = docsTree();
    $module = "export function greet(name: string): string {\n  return 'Hello, ' + name;\n}\n";
    $spec = "import { expect, test } from 'vitest';\n\nimport { greet } from './greeter';\n\ntest('greets by name', () => {\n  expect(greet('Ada')).toBe('Hello, Ada');\n});\n";
    docsWrite($root, 'vitest.config.ts', DOCS_VITEST);
    docsWrite($root, 'examples/Vitest/Greeting/greeter.ts', $module);
    docsWrite($root, 'examples/Vitest/Greeting/greeter.test.ts', $spec);
    docsWrite($root, DOCS_GREETER_PAGE, docsPage(
        'Cbox\Cms\Contracts\Greeter',
        docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST),
        docsEmbed('example-file', 'examples/Vitest/Greeting/greeter.ts', $module, 'ts'),
        docsEmbed('example', 'examples/Vitest/Greeting/greeter.test.ts', $spec, 'ts'),
    ));

    expect(docsFindings($root))->toBe([]);
});

it('reads the include patterns of the unit project from vitest.config.ts, matches them as Vitest does, and includes nothing without the file or the project', function (): void {
    $root = ScratchDirectory::make('cbox-cms-docs-vitest-');
    docsWrite($root, 'vitest.config.ts', DOCS_VITEST);
    docsWrite($root, 'other.config.ts', str_replace("name: 'unit'", "name: 'kit'", DOCS_VITEST));
    $suite = VitestUnitSuite::read($root.'/vitest.config.ts');

    expect($suite->includes)->toBe(['js/*/tests/**/*.test.{js,ts,tsx}', 'examples/Vitest/**/*.test.{ts,tsx}'])
        ->and($suite->includes('js/panel/tests/host/slots.test.tsx'))->toBeTrue()
        ->and($suite->includes('js/ui-kit/tests/tokens.test.js'))->toBeTrue()
        ->and($suite->includes('examples/Vitest/Panel/slot.test.tsx'))->toBeTrue()
        ->and($suite->includes('examples/Vitest/deep/er/slot.test.ts'))->toBeTrue()
        ->and($suite->includes('examples/Vitest/Panel/slot.test.js'))->toBeFalse()
        ->and($suite->includes('examples/Unit/Panel/slot.test.ts'))->toBeFalse()
        ->and($suite->includes('js/panel/src/slots.test.tsx'))->toBeFalse()
        ->and($suite->includes('js/panel/tests/slots.tsx'))->toBeFalse()
        ->and(VitestUnitSuite::read($root.'/missing.config.ts')->includes)->toBe([])
        ->and(VitestUnitSuite::read($root.'/other.config.ts')->includes)->toBe([])
        ->and(JsSuite::none()->names())->toBe('no include')
        ->and(JsSuite::isTestFile('a/b.test.tsx'))->toBeTrue()
        ->and(JsSuite::isTestFile('a/b.test.js'))->toBeFalse()
        ->and(JsSuite::isTestFile('a/bTest.php'))->toBeFalse();
});

it('includes every TypeScript example of this repository in the JS unit suite, so npm run test:js runs it', function (): void {
    $tree = LocalDocsTree::read(Phpstan::root());

    expect($tree->jsExamples)->not->toBe([])
        ->and(array_values(array_filter($tree->jsExamples, static fn (string $path): bool => ! $tree->jsSuite->includes($path))))->toBe([]);
});

it('takes an assert method, or the use of a trait of the inventory as a shared contract suite\'s test class does, as an assertion', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/testkit/src/Greeting/GreeterContract.php', docsPhp('Cbox\Cms\Testkit\Greeting', 'trait GreeterContract {}'));
    $suite = docsPhp('Examples\Unit\Greeting', "final class SuiteTest extends TestCase\n{\n    use GreeterContract;\n}", "use Cbox\\Cms\\Testkit\\Greeting\\GreeterContract;\nuse PHPUnit\\Framework\\TestCase;\n\n");
    $asserting = "<?php\n\ndeclare(strict_types=1);\n\nit('greets by name', function (): void {\n    \$this->assertSame('Hello, Ada', 'Hello, Ada');\n});\n";
    docsWrite($root, 'examples/Unit/Greeting/SuiteTest.php', $suite);
    docsWrite($root, 'examples/Unit/Greeting/AssertingTest.php', $asserting);
    docsWrite($root, 'docs/addons/greeter-contract.md', docsPage(
        'Cbox\Cms\Testkit\Greeting\GreeterContract',
        docsEmbed('example', 'examples/Unit/Greeting/SuiteTest.php', $suite),
        docsEmbed('example', 'examples/Unit/Greeting/AssertingTest.php', $asserting),
    ));

    expect(docsFindings($root))->toBe([]);
});

it('reports an example-file block that differs from its file', function (): void {
    $root = docsTree();
    docsWrite($root, 'examples/Unit/Greeting/greeting.yaml', "name: Ada\n");
    $test = "<?php\n\ndeclare(strict_types=1);\n\nit('reads greeting.yaml', function (): void {\n    expect(file_get_contents(__DIR__.'/greeting.yaml'))->toBe(\"name: Ada\\n\");\n});\n";
    docsWrite($root, DOCS_GREETER_EXAMPLE, $test);
    docsWrite($root, DOCS_GREETER_PAGE, docsPage(
        'Cbox\Cms\Contracts\Greeter',
        docsEmbed('example', DOCS_GREETER_EXAMPLE, $test),
        docsEmbed('example-file', 'examples/Unit/Greeting/greeting.yaml', "name: Grace\n", 'yaml'),
    ));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeter.md:24: the fenced block differs from examples/Unit/Greeting/greeting.yaml; embed the file byte for byte',
    ]);
});

it('reports an example-file that no example on the page mentions', function (): void {
    $root = docsTree();
    $support = docsPhp('Examples\Unit\Greeting', "final readonly class PoliteGreeter {}\n");
    docsWrite($root, 'examples/Unit/Greeting/PoliteGreeter.php', $support);
    docsWrite($root, DOCS_GREETER_PAGE, docsPage(
        'Cbox\Cms\Contracts\Greeter',
        docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST),
        docsEmbed('example-file', 'examples/Unit/Greeting/PoliteGreeter.php', $support),
    ));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeter.md:24: no example test on this page mentions PoliteGreeter, so nothing shows examples/Unit/Greeting/PoliteGreeter.php in use',
    ]);
});

it('reports a fenced block that is no checked embed, an unclosed one and a marker that no block follows', function (): void {
    $root = docsTree();
    docsWrite($root, DOCS_GREETER_PAGE, docsPage(
        'Cbox\Cms\Contracts\Greeter',
        docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST),
        "Run this:\n\n```bash\ncomposer docs:check\n```\n",
        "<!-- example-file: examples/Unit/Greeting/GreeterTest.php -->\n\n~~~\nleft open\n",
    ));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeter.md:26: the fenced block is no checked embed; put a command in inline code, and embed a file with <!-- example: <path> --> or <!-- example-file: <path> --> on the line above',
        'docs/addons/greeter.md:30: <!-- example-file: examples/Unit/Greeting/GreeterTest.php --> is not followed immediately by a fenced block',
        'docs/addons/greeter.md:32: the fenced block is no checked embed; put a command in inline code, and embed a file with <!-- example: <path> --> or <!-- example-file: <path> --> on the line above',
        'docs/addons/greeter.md:32: the fenced block is not closed',
    ]);
});

it('reports a marker path that is not repo-relative', function (): void {
    $root = docsTree();
    docsWrite($root, DOCS_GREETER_PAGE, docsPage(
        'Cbox\Cms\Contracts\Greeter',
        docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST),
        docsEmbed('example-file', '../Greeting/GreeterTest.php', DOCS_GREETER_TEST),
        docsEmbed('example-file', '/examples/Unit/Greeting/GreeterTest.php', DOCS_GREETER_TEST),
    ));

    expect(docsFindings($root))->toBe([
        'docs/addons/greeter.md:24: ../Greeting/GreeterTest.php is not a repo-relative path',
        'docs/addons/greeter.md:35: /examples/Unit/Greeting/GreeterTest.php is not a repo-relative path',
    ]);
});

it('reports an example in a namespace below Cbox\Cms, and one in a namespace outside Examples', function (): void {
    $root = docsTree();
    docsWrite($root, 'examples/Unit/Greeting/InternalGreeter.php', docsPhp('Cbox\Cms\Examples\Greeting', 'final readonly class InternalGreeter {}'));
    docsWrite($root, 'examples/Unit/Greeting/AcmeGreeter.php', docsPhp('Acme\Greeting', 'final readonly class AcmeGreeter {}'));

    expect(docsFindings($root))->toBe([
        'examples/Unit/Greeting/AcmeGreeter.php:5: the example is in the namespace Acme\Greeting; an example is a Pest file in the global namespace or a class below Examples\\',
        'examples/Unit/Greeting/InternalGreeter.php:5: the example is in the namespace Cbox\Cms\Examples\Greeting; an example is a Pest file in the global namespace or a class below Examples\\, so it shows only what an application or addon may use and never #[Internal] API',
    ]);
});

it('reports a *Test.php under examples/ that no page embeds', function (): void {
    $root = docsTree();
    docsWrite($root, 'examples/Unit/Greeting/OrphanTest.php', DOCS_GREETER_TEST);

    expect(docsFindings($root))->toBe([
        'examples/Unit/Greeting/OrphanTest.php:1: no page embeds this example; embed it on the page of what it shows with <!-- example: examples/Unit/Greeting/OrphanTest.php -->',
    ]);
});

it('reports an exclusion without a reason, which excludes nothing', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/core/src/Greeting/Domain/Farewell.php', docsPhp('Cbox\Cms\Core\Greeting\Domain', 'interface Farewell {}'));

    expect(docsFindings($root, [new Exclusion('Cbox\Cms\Core\Greeting\Domain\Farewell', ' ')]))->toBe([
        'Cbox\Cms\Core\Greeting\Domain\Farewell: excluded from the inventory of extension points without a reason, so it is not excluded; give the reason',
        'Cbox\Cms\Core\Greeting\Domain\Farewell: undocumented',
    ]);
});

it('reports a stale exclusion', function (): void {
    expect(docsFindings(docsTree(), [new Exclusion('Cbox\Cms\Contracts\Gone', 'a union that nothing implements')]))->toBe([
        'Cbox\Cms\Contracts\Gone: excluded from the inventory of extension points, but packages/*/src and packages/*/resources/schemas have no such interface, attribute class, trait, #[Command], #[Hook] or #[PanelPoint] class or schema that is not #[Internal]; remove the stale exclusion',
    ]);
});

it('reports a missing root page, another file at the root of docs/, and a folder without _index.md, nested or not', function (): void {
    $root = docsTree();
    unlink($root.'/docs/requirements.md');
    docsWrite($root, 'docs/roadmap.md', docsFrontmatter('Roadmap', 4)."# Roadmap\n");
    docsWrite($root, 'docs/guides/recipes/one.md', docsFrontmatter('One', 41)."# One\n");

    expect(docsFindings($root))->toBe([
        'docs/guides: the folder has no _index.md; every folder below docs/ has one, with title, weight and description frontmatter',
        'docs/guides/recipes: the folder has no _index.md; every folder below docs/ has one, with title, weight and description frontmatter',
        'docs/requirements.md: missing; the root of docs/ holds index.md, quickstart.md and requirements.md',
        'docs/roadmap.md: the root of docs/ holds only index.md, quickstart.md and requirements.md; move the file into a topic folder',
    ]);
});

it('reports a page without frontmatter, one without each key, a weight that is no whole number and a line that is not key: value', function (): void {
    $root = docsTree();
    docsWrite($root, 'docs/addons/bare.md', "# Bare\n");
    docsWrite($root, 'docs/addons/empty.md', "---\ntitle: ''\n---\n\n# Empty\n");
    docsWrite($root, 'docs/addons/odd.md', "---\ntitle: \"Odd: a page\"\nweight: 3.5\ndescription: 'It''s odd.'\n- a list item\n---\n\n# Odd\n");
    docsWrite($root, 'docs/addons/open.md', "---\ntitle: Open\n\n# Open, never closed\n");

    expect(docsFindings($root))->toBe([
        'docs/addons/bare.md:1: the page has no frontmatter; start it with a line ---, then title, weight and description, then a line ---',
        'docs/addons/empty.md:1: the frontmatter has no description',
        'docs/addons/empty.md:1: the frontmatter has no title',
        'docs/addons/empty.md:1: the frontmatter has no weight',
        'docs/addons/odd.md:1: the frontmatter weight is not a whole number',
        'docs/addons/odd.md:5: the frontmatter line is not key: value',
        'docs/addons/open.md:1: the page has no frontmatter; start it with a line ---, then title, weight and description, then a line ---',
    ]);
});

it('reads quoted frontmatter values, and a comment after a plain one', function (): void {
    $page = PageParser::parse('docs/a.md', "---\ntitle: \"Say \\\"hi\\\": now\"\nweight: 12 # after the section\ndescription: 'It''s here.'\n---\n# A\n");

    expect($page->frontmatter?->value('title'))->toBe('Say "hi": now')
        ->and($page->frontmatter?->weight())->toBe(12)
        ->and($page->frontmatter?->value('description'))->toBe("It's here.")
        ->and($page->frontmatter?->lastLine)->toBe(5);
});

it('reports a page whose weight is not higher than that of the _index.md of its folder', function (): void {
    $root = docsTree();
    docsWrite($root, 'docs/addons/first.md', docsFrontmatter('First', 30)."# First\n");
    docsWrite($root, 'docs/addons/zeroth.md', docsFrontmatter('Zeroth', 5)."# Zeroth\n");

    expect(docsFindings($root))->toBe([
        'docs/addons/first.md:1: the weight 30 is not higher than that of docs/addons/_index.md, 30; the section page comes first',
        'docs/addons/zeroth.md:1: the weight 5 is not higher than that of docs/addons/_index.md, 30; the section page comes first',
    ]);
});

it('reports a file below docs/ that is no page and no screenshot, and a Markdown file below packages/', function (): void {
    $root = docsTree();
    docsWrite($root, 'docs/addons/diagram.png', 'png');
    docsWrite($root, 'packages/contracts/docs/greeter.md', "# Left behind\n");
    docsWrite($root, 'packages/contracts/resources/schemas/greeting.md', "# Left behind\n");

    expect(docsFindings($root))->toBe([
        'docs/addons/diagram.png: below docs/ there are only Markdown pages, and the screenshots of Cbox\Cms\Tooling\Docs\Domain\Screenshots in docs/screenshots',
        'packages/contracts/docs/greeter.md: the documentation is in docs/ at the root of the repository; move the page there',
        'packages/contracts/resources/schemas/greeting.md: the documentation is in docs/ at the root of the repository; move the page there',
    ]);
});

it('reports a relative link to a missing file, one that leaves the repository and one to a missing heading, on a page and in README.md', function (): void {
    $root = docsTree();
    docsWrite($root, 'README.md', "# Greeter\n\n[Docs](docs/index.md), [gone](docs/gone.md) and [the site](https://example.com/docs).\n");
    docsWrite($root, 'docs/addons/links.md', docsFrontmatter('Links', 32).implode("\n", [
        '# Links',
        '',
        '## Where to go',
        '',
        '- [a folder](../addons/) and [a file with a query](greeter.md?plain=1) resolve',
        '- [the page](greeter.md#a-page), [this heading](#where-to-go) and [mail](mailto:dev@example.com) resolve',
        '- [gone](gone.md), [outside](../../../outside.md), [no heading](greeter.md#nowhere) and [not here](#nowhere) do not',
        '- `[in code](gone.md)` is no link',
        '',
        '[defined]: ../missing/',
        '',
        '```',
        '[in a block](gone.md)',
        '```',
        '',
    ]));

    expect(docsFindings($root))->toBe([
        'README.md:3: the link docs/gone.md points to docs/gone.md, which does not exist',
        'docs/addons/links.md:13: the link #nowhere names the heading #nowhere, which the page does not have',
        'docs/addons/links.md:13: the link ../../../outside.md leaves the repository',
        'docs/addons/links.md:13: the link gone.md points to docs/addons/gone.md, which does not exist',
        'docs/addons/links.md:13: the link greeter.md#nowhere names the heading #nowhere, which docs/addons/greeter.md does not have',
        'docs/addons/links.md:16: the link ../missing/ points to docs/missing, which does not exist',
        'docs/addons/links.md:18: the fenced block is no checked embed; put a command in inline code, and embed a file with <!-- example: <path> --> or <!-- example-file: <path> --> on the line above',
    ]);
});

it('reports each missing file of the repository root: README.md, LICENSE, SECURITY.md and CONTRIBUTING.md', function (string $path): void {
    $root = docsTree();
    unlink($root.'/'.$path);

    expect(docsFindings($root))->toBe([
        "{$path}: missing; the root of the repository has README.md, LICENSE, SECURITY.md and CONTRIBUTING.md",
    ]);
})->with(['README.md', 'LICENSE', 'SECURITY.md', 'CONTRIBUTING.md']);

it('reports a dangling link or image in CONTRIBUTING.md and SECURITY.md as in README.md, and leaves LICENSE unread', function (): void {
    $root = docsTree();
    docsWrite($root, 'CONTRIBUTING.md', "# Contributing\n\nRead [the quickstart](docs/quickstart.md) and [the rules](docs/rules.md).\n");
    docsWrite($root, 'SECURITY.md', "# Security\n\n![A diagram](docs/security/diagram.svg) and [the form](https://github.com/cboxdk/cms/security/advisories/new).\n");
    docsWrite($root, 'LICENSE', "MIT License\n\n[not a link](gone.md)\n");

    expect(docsFindings($root))->toBe([
        'CONTRIBUTING.md:3: the link docs/rules.md points to docs/rules.md, which does not exist',
        'SECURITY.md:3: the link docs/security/diagram.svg points to docs/security/diagram.svg, which does not exist',
    ]);
});

it('requires README.md to embed a screenshot with its caption, and counts it as the embed of its entry', function (): void {
    $root = docsTree();
    $shots = [new Screenshot('greeting', ['php', '-r', 'echo 1;'], 'The greeting.')];
    docsWrite($root, 'docs/screenshots/_index.md', docsFrontmatter('Screenshots', 90)."# Screenshots\n\n![The greeting.](greeting.svg)\n");
    docsWrite($root, 'docs/screenshots/greeting.svg', '<svg/>');

    $without = docsFindings($root, [], $shots);

    docsWrite($root, 'README.md', "# Greeter\n\n![A greeting](docs/screenshots/greeting.svg)\n");
    $wrongCaption = docsFindings($root, [], $shots);

    docsWrite($root, 'README.md', "# Greeter\n\n![The greeting.](docs/screenshots/greeting.svg)\n");

    expect($without)->toBe([
        'README.md: embeds no screenshot; show at least one image of Screenshots, with its caption as the alt text',
        'docs/screenshots/greeting.svg: no page outside docs/screenshots embeds it; embed it on the page that describes it, or remove its entry from Screenshots',
    ])
        ->and($wrongCaption)->toBe([
            'README.md:3: the alt text of docs/screenshots/greeting.svg is not the caption of its entry in Screenshots: The greeting.',
        ])
        ->and(docsFindings($root, [], $shots))->toBe([]);
});

it('gives a heading the anchor GitHub gives it', function (): void {
    $page = PageParser::parse('docs/a.md', implode("\n", [
        '# The contract: DoctorCheck',
        '## Exit codes: `DoctorExitCode`',
        '## Processes: web, queue and maintenance',
        '## Testing a [check](b.md)',
        '### Adding a check',
        '### Adding a check',
        '#no-heading',
        '',
    ]));

    expect($page->anchors)->toBe([
        'the-contract-doctorcheck',
        'exit-codes-doctorexitcode',
        'processes-web-queue-and-maintenance',
        'testing-a-check',
        'adding-a-check',
        'adding-a-check-1',
    ]);
});

it('reports a screenshot without its file, a file without an entry, an entry no page embeds and a caption that is not the entry\'s', function (): void {
    $root = docsTree();
    $shots = [
        new Screenshot('greeting', ['php', '-r', 'echo 1;'], 'The greeting.'),
        new Screenshot('farewell', ['php', '-r', 'echo 2;'], 'The farewell.'),
        new Screenshot('missing', ['php', '-r', 'echo 3;'], 'Not captured.'),
    ];
    docsWrite($root, 'docs/screenshots/_index.md', docsFrontmatter('Screenshots', 90)."# Screenshots\n\n![The greeting.](greeting.svg)\n![The farewell.](farewell.svg)\n![Not captured.](missing.svg)\n");
    docsWrite($root, 'docs/screenshots/greeting.svg', '<svg/>');
    docsWrite($root, 'docs/screenshots/farewell.svg', '<svg/>');
    docsWrite($root, 'docs/screenshots/stray.svg', '<svg/>');
    docsWrite($root, 'docs/index.md', docsFrontmatter('Greeter', 1)."# Greeter\n\nSee [the addons](addons/_index.md).\n\n![A greeting](screenshots/greeting.svg)\n");

    expect(docsFindings($root, [], $shots))->toBe([
        'docs/index.md:11: the alt text of docs/screenshots/greeting.svg is not the caption of its entry in Screenshots: The greeting.',
        'docs/screenshots/_index.md:11: the link missing.svg points to docs/screenshots/missing.svg, which does not exist',
        'README.md: embeds no screenshot; show at least one image of Screenshots, with its caption as the alt text',
        'docs/screenshots/farewell.svg: no page outside docs/screenshots embeds it; embed it on the page that describes it, or remove its entry from Screenshots',
        'docs/screenshots/missing.svg: missing; capture it with composer docs:screenshots -- --only=missing',
        'docs/screenshots/missing.svg: no page outside docs/screenshots embeds it; embed it on the page that describes it, or remove its entry from Screenshots',
        'docs/screenshots/stray.svg: no entry of Cbox\Cms\Tooling\Docs\Domain\Screenshots names this file; add one, or remove the file',
    ]);
});

it('holds a browser screenshot to its PNG, its embed and its caption as it holds a terminal one', function (): void {
    $root = docsTree();
    $shots = [
        new BrowserScreenshot('login', 'The login page.', 'tests/Browser/Panel/LoginTest.php'),
        new BrowserScreenshot('shell', 'The shell.', 'tests/Browser/Panel/ShellTest.php'),
        new BrowserScreenshot('bare', 'Bare.', ''),
    ];
    docsWrite($root, 'docs/screenshots/_index.md', docsFrontmatter('Screenshots', 90)."# Screenshots\n");
    docsWrite($root, 'docs/screenshots/login.png', 'png');
    docsWrite($root, 'docs/screenshots/bare.png', 'png');
    docsWrite($root, 'docs/index.md', docsFrontmatter('Greeter', 1)."# Greeter\n\nSee [the addons](addons/_index.md).\n\n![The login page.](screenshots/login.png)\n![Bare.](screenshots/bare.png)\n");
    docsWrite($root, 'README.md', "# Greeter\n\n![The login page.](docs/screenshots/login.png)\n");

    expect(docsFindings($root, [], $shots))->toBe([
        'bare: a screenshot has a caption and a command',
        'docs/screenshots/shell.png: missing; capture it with composer image:run -- env CMS_DOCS_SCREENSHOTS=1 vendor/bin/pest --testsuite=Browser tests/Browser/Panel/ShellTest.php',
        'docs/screenshots/shell.png: no page outside docs/screenshots embeds it; embed it on the page that describes it, or remove its entry from Screenshots',
    ]);
});

it('lists this repository\'s browser screenshots once each, each with its Browser test and a caption that ends a sentence', function (): void {
    $keys = array_map(static fn (BrowserScreenshot $shot): string => $shot->key, Screenshots::browser());

    expect($keys)->toBe(array_values(array_unique($keys)))
        ->and(array_filter(Screenshots::browser(), static fn (BrowserScreenshot $shot): bool => ! is_file(Phpstan::root().'/'.$shot->test) || ! str_ends_with($shot->caption, '.')))->toBe([]);
});

it('reports a screenshot key that is not lowercase words joined by hyphens, one used twice, and one without a caption', function (): void {
    $root = docsTree();
    $shots = [
        new Screenshot('Greeting_Page', ['php'], 'A caption.'),
        new Screenshot('twice', ['php'], 'Twice.'),
        new Screenshot('twice', ['php'], 'Twice again.'),
        new Screenshot('bare', [], ' '),
    ];

    expect(array_values(array_filter(docsFindings($root, [], $shots), static fn (string $finding): bool => ! str_starts_with($finding, 'docs/screenshots/'))))->toBe([
        'Greeting_Page: the key of a screenshot is lowercase words and digits joined by hyphens',
        'README.md: embeds no screenshot; show at least one image of Screenshots, with its caption as the alt text',
        'bare: a screenshot has a caption and a command',
        'twice: the key of a screenshot is used twice in Screenshots',
    ]);
});

it('lists this repository\'s screenshots once each, each with a command, a caption that ends a sentence, and a findable key', function (): void {
    $keys = array_map(static fn (Screenshot $shot): string => $shot->key, Screenshots::all());

    expect($keys)->toBe(array_values(array_unique($keys)))
        ->and(array_filter(Screenshots::all(), static fn (Screenshot $shot): bool => $shot->command === [] || ! str_ends_with($shot->caption, '.')))->toBe([])
        ->and(Screenshots::find('doctor')?->promptLine())->toBe('vendor/bin/testbench cms:doctor')
        ->and(Screenshots::find('doctor-violation')?->exitCode)->toBe(78)
        ->and(Screenshots::find('nothing'))->toBeNull();
});

it('gives every exclusion of the Docs module a reason, and each names something the inventory rule finds in this repository', function (): void {
    $names = array_map(static fn (Exclusion $exclusion): string => $exclusion->name, Exclusions::all());
    $findings = array_map(static fn (Finding $finding): string => (string) $finding, DocsAudit::findings(LocalDocsTree::read(Phpstan::root()), Exclusions::all(), [...Screenshots::all(), ...Screenshots::browser()]));

    expect($names)->toBe([
        ClaimResult::class,
        Stable::class,
        Experimental::class,
        Internal::class,
    ])
        ->and(array_filter(Exclusions::all(), static fn (Exclusion $exclusion): bool => ! $exclusion->hasReason()))->toBe([])
        ->and(array_values(array_filter($findings, static fn (string $finding): bool => array_any($names, static fn (string $name): bool => str_starts_with($finding, $name.':')))))->toBe([]);
});

it('excludes nothing of the testkit, the API an addon tests with, and documents its Postgres and Valkey harnesses on one page', function (): void {
    $tree = LocalDocsTree::read(Phpstan::root());
    $inventory = Inventory::of($tree->sources, $tree->schemas, Exclusions::all());
    /** @var array<string, list<string>> $pagesOf the pages that declare each extension point */
    $pagesOf = [];

    foreach ($tree->pages as $page) {
        foreach ($page->markers(MarkerKind::ExtensionPoint) as $marker) {
            $pagesOf[$marker->target][] = $page->path;
        }
    }

    $testkit = array_values(array_filter(
        array_map(static fn (Exclusion $exclusion): string => $exclusion->name, Exclusions::all()),
        static fn (string $name): bool => str_starts_with($name, 'Cbox\\Cms\\Testkit\\'),
    ));

    expect($testkit)->toBe([])
        ->and($inventory->excluded)->not->toHaveKeys([RealPostgres::class, RealValkey::class])
        ->and($inventory->has(RealPostgres::class))->toBeTrue()
        ->and($inventory->has(RealValkey::class))->toBeTrue()
        ->and($pagesOf[RealPostgres::class] ?? [])->toBe(['docs/addons/real-services.md'])
        ->and($pagesOf[RealValkey::class] ?? [])->toBe(['docs/addons/real-services.md']);
});

it('finds in this repository nothing but undocumented extension points, and nothing for the blueprint schema and its page', function (): void {
    $findings = array_map(static fn (Finding $finding): string => (string) $finding, DocsAudit::findings(LocalDocsTree::read(Phpstan::root()), Exclusions::all(), [...Screenshots::all(), ...Screenshots::browser()]));

    expect(array_values(array_filter($findings, static fn (string $finding): bool => ! str_ends_with($finding, ': undocumented'))))->toBe([])
        ->and(array_values(array_filter($findings, static fn (string $finding): bool => str_contains($finding, 'blueprint.v1'))))->toBe([]);
});

it('reads declarations, attributes, trait uses and calls from tokens, as PHP resolves the names', function (): void {
    $file = PhpTokens::read('example.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Acme\Shop;

        use Attribute;
        use Acme\Kernel\{Marker, Suite as Shared};
        use function Acme\helper;

        #[Attribute(Attribute::TARGET_CLASS), Marker([1, 2])]
        final readonly class Colour
        {
            use Shared, \Acme\Other\Extra {
                Shared::run insteadof Extra;
            }

            public function run(): void
            {
                $name = self::class;
                $closure = function () use ($name): void {
                    expect($name)->toBe(Colour::class);
                };
                new class {
                    use Nested;
                };
            }
        }

        interface Paintable {}

        enum Shade: string {}
        PHP);

    expect(array_map(static fn (DeclaredType $type): array => [$type->name, $type->kind, $type->attributes, $type->line], $file->types))->toBe([
        ['Acme\Shop\Colour', TypeKind::Class_, ['Attribute', 'Acme\Kernel\Marker'], 12],
        ['Acme\Shop\Paintable', TypeKind::Interface, [], 30],
        ['Acme\Shop\Shade', TypeKind::Enum, [], 32],
    ])
        ->and($file->traitUses)->toBe(['Acme\Kernel\Suite', 'Acme\Other\Extra', 'Acme\Shop\Nested'])
        ->and($file->calls)->toContain('expect', 'toBe')
        ->and(in_array('run', $file->calls, true))->toBeFalse()
        ->and($file->namespaces[0]->name ?? null)->toBe('Acme\Shop');
});

it('reads markers outside fenced blocks only, and a fence of four backticks around one of three', function (): void {
    $page = PageParser::parse('page.md', "<!-- example: a/FirstTest.php -->\n````php\n```\n<!-- example: b/InsideTest.php -->\n````\n");

    expect(array_map(static fn (Marker $marker): string => $marker->target, $page->markers))->toBe(['a/FirstTest.php'])
        ->and($page->embeds[0]->body ?? null)->toBe("```\n<!-- example: b/InsideTest.php -->\n")
        ->and($page->strayFences)->toBe([]);
});

it('parses --root, and refuses anything else', function (): void {
    expect(DocsCheckOptions::parse([])->root)->toBeNull()
        ->and(DocsCheckOptions::parse(['--root=/tmp/tree'])->root)->toBe('/tmp/tree');

    expect(fn (): DocsCheckOptions => DocsCheckOptions::parse(['--root=']))->toThrow(InvalidArgumentException::class)
        ->and(fn (): DocsCheckOptions => DocsCheckOptions::parse(['--root=a', '--root=b']))->toThrow(InvalidArgumentException::class)
        ->and(fn (): DocsCheckOptions => DocsCheckOptions::parse(['--fix']))->toThrow(InvalidArgumentException::class);
});

it('exposes the check as composer docs:check', function (): void {
    expect(ComposerScripts::steps('docs:check'))->toBe(['@php tools/bin/docs-check.php'])
        ->and(ComposerScripts::description('docs:check'))->toContain('Gate 10', 'exit 1', '--root=<dir>');
});

/**
 * A scratch copy of what the documentation check reads in this repository: the sources and
 * schemas of the packages, docs/, README.md, CONTRIBUTING.md, SECURITY.md, LICENSE, examples/,
 * phpunit.xml, vitest.config.ts and every file a page embeds or links to.
 */
function docsRepositoryCopy(): string
{
    $repository = Phpstan::root();
    $scratch = ScratchDirectory::make('cbox-cms-docs-check-');
    $copy = static function (string $from, string $to): void {
        if (is_file($from)) {
            ScratchDirectory::write($to, (string) file_get_contents($from));

            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                ScratchDirectory::write($to.substr($file->getPathname(), strlen($from)), (string) file_get_contents($file->getPathname()));
            }
        }
    };

    foreach ([...(glob($repository.'/packages/*/src', GLOB_ONLYDIR) ?: []), ...(glob($repository.'/packages/*/resources', GLOB_ONLYDIR) ?: []), $repository.'/docs', $repository.'/README.md', $repository.'/CONTRIBUTING.md', $repository.'/SECURITY.md', $repository.'/LICENSE', $repository.'/examples', $repository.'/phpunit.xml', $repository.'/vitest.config.ts'] as $path) {
        $copy($path, $scratch.substr($path, strlen($repository)));
    }

    foreach (LocalDocsTree::read($repository)->pages as $page) {
        foreach ($page->markers as $marker) {
            if ($marker->kind->embeds() && is_file($repository.'/'.$marker->target)) {
                $copy($repository.'/'.$marker->target, $scratch.'/'.$marker->target);
            }
        }

        foreach ($page->links as $link) {
            $target = $link->isRelative() && $link->path() !== '' ? realpath(dirname($repository.'/'.$page->path).'/'.$link->path()) : false;

            if ($target !== false && is_file($target) && str_starts_with($target, $repository.'/')) {
                $copy($target, $scratch.substr($target, strlen($repository)));
            }
        }
    }

    return $scratch;
}

it('fails on a copy of this repository with one new public interface in the contracts, and names it undocumented', function (): void {
    $scratch = docsRepositoryCopy();
    [$before, $beforeOutput] = runDocsCheck('--root='.$scratch);

    docsWrite($scratch, 'packages/contracts/src/Salutation.php', docsPhp('Cbox\Cms\Contracts', "#[Experimental]\ninterface Salutation {}", "use Cbox\\Cms\\Contracts\\Attributes\\Experimental;\n\n"));
    [$exitCode, $output, $errors] = runDocsCheck('--root='.$scratch);

    ScratchDirectory::delete($scratch);

    expect(explode("\n", $beforeOutput))->not->toContain('Cbox\Cms\Contracts\Salutation: undocumented')
        ->and($before)->toBe(0)
        ->and($exitCode)->toBe(1)
        ->and(explode("\n", $output))->toContain('Cbox\Cms\Contracts\Salutation: undocumented')
        ->and($errors)->toContain('docs:check:')
        ->and(is_dir($scratch))->toBeFalse();
});

it('fails on a copy of this repository with a planted missing _index.md, a page without frontmatter, a dangling link, a missing screenshot or an unused one, and names each', function (string $plant, string $finding): void {
    $scratch = docsRepositoryCopy();
    [$before] = runDocsCheck('--root='.$scratch);
    $quickstart = (string) file_get_contents($scratch.'/docs/quickstart.md');

    match ($plant) {
        'missing _index.md' => unlink($scratch.'/docs/security/_index.md'),
        'missing frontmatter' => docsWrite($scratch, 'docs/developers/partitions.md', (string) preg_replace('/\A---\n.*?\n---\n\n/s', '', (string) file_get_contents($scratch.'/docs/developers/partitions.md'))),
        'dangling link' => docsWrite($scratch, 'docs/quickstart.md', $quickstart."\nSee [the roadmap](roadmap.md).\n"),
        'missing screenshot' => unlink($scratch.'/docs/screenshots/doctor.svg'),
        'screenshot without an entry' => docsWrite($scratch, 'docs/screenshots/extra.svg', (string) file_get_contents($scratch.'/docs/screenshots/doctor.svg')),
        'screenshot no page embeds' => docsWrite($scratch, 'docs/developers/doctor.md', (string) preg_replace('/^!\[[^\n]*\]\(\.\.\/screenshots\/doctor-violation\.svg\)\n/m', '', (string) file_get_contents($scratch.'/docs/developers/doctor.md'))),
        default => throw new InvalidArgumentException($plant),
    };

    [$exitCode, $output] = runDocsCheck('--root='.$scratch);

    ScratchDirectory::delete($scratch);

    expect($before)->toBe(0)
        ->and($exitCode)->toBe(1)
        ->and(explode("\n", $output))->toContain(str_replace('{line}', (string) (substr_count($quickstart, "\n") + 2), $finding));
})->with([
    'missing _index.md' => ['missing _index.md', 'docs/security: the folder has no _index.md; every folder below docs/ has one, with title, weight and description frontmatter'],
    'missing frontmatter' => ['missing frontmatter', 'docs/developers/partitions.md:1: the page has no frontmatter; start it with a line ---, then title, weight and description, then a line ---'],
    'dangling link' => ['dangling link', 'docs/quickstart.md:{line}: the link roadmap.md points to docs/roadmap.md, which does not exist'],
    'missing screenshot' => ['missing screenshot', 'docs/screenshots/doctor.svg: missing; capture it with composer docs:screenshots -- --only=doctor'],
    'screenshot without an entry' => ['screenshot without an entry', 'docs/screenshots/extra.svg: no entry of Cbox\Cms\Tooling\Docs\Domain\Screenshots names this file; add one, or remove the file'],
    'screenshot no page embeds' => ['screenshot no page embeds', 'docs/screenshots/doctor-violation.svg: no page outside docs/screenshots embeds it; embed it on the page that describes it, or remove its entry from Screenshots'],
]);

it('fails on a copy of this repository with a planted broken link or image in README.md, CONTRIBUTING.md or SECURITY.md, or without LICENSE, and names each', function (string $path, string $plant, string $finding): void {
    $scratch = docsRepositoryCopy();
    [$before] = runDocsCheck('--root='.$scratch);

    if ($plant === '') {
        unlink($scratch.'/'.$path);
        $line = 0;
    } else {
        $contents = (string) file_get_contents($scratch.'/'.$path);
        docsWrite($scratch, $path, $contents."\n".$plant."\n");
        $line = substr_count($contents, "\n") + 2;
    }

    [$exitCode, $output] = runDocsCheck('--root='.$scratch);

    ScratchDirectory::delete($scratch);

    expect($before)->toBe(0)
        ->and($exitCode)->toBe(1)
        ->and(explode("\n", $output))->toContain(str_replace('{line}', (string) $line, $finding));
})->with([
    'broken link in README.md' => ['README.md', 'See [the roadmap](docs/roadmap.md).', 'README.md:{line}: the link docs/roadmap.md points to docs/roadmap.md, which does not exist'],
    'broken image in README.md' => ['README.md', '![A shot](docs/screenshots/gone.svg)', 'README.md:{line}: the link docs/screenshots/gone.svg points to docs/screenshots/gone.svg, which does not exist'],
    'broken link in CONTRIBUTING.md' => ['CONTRIBUTING.md', 'See [the rules](docs/developers/rules.md).', 'CONTRIBUTING.md:{line}: the link docs/developers/rules.md points to docs/developers/rules.md, which does not exist'],
    'broken heading in SECURITY.md' => ['SECURITY.md', 'See [the scope](docs/security/scope.md#nowhere).', 'SECURITY.md:{line}: the link docs/security/scope.md#nowhere names the heading #nowhere, which docs/security/scope.md does not have'],
    'missing LICENSE' => ['LICENSE', '', 'LICENSE: missing; the root of the repository has README.md, LICENSE, SECURITY.md and CONTRIBUTING.md'],
]);

it('exits 2 on a usage error and 1 on a root that is not a directory', function (): void {
    [$usage, , $usageErrors] = runDocsCheck('--fix');
    [$missing, , $missingErrors] = runDocsCheck('--root=/nonexistent/cbox-cms-docs');

    expect($usage)->toBe(2)
        ->and($usageErrors)->toContain('Usage: php tools/bin/docs-check.php [--root=<dir>]')
        ->and($missing)->toBe(1)
        ->and($missingErrors)->toContain('/nonexistent/cbox-cms-docs is not a directory.');
});
