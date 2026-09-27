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
use Cbox\Cms\Tooling\Docs\Domain\DeclaredType;
use Cbox\Cms\Tooling\Docs\Domain\DocsAudit;
use Cbox\Cms\Tooling\Docs\Domain\Exclusion;
use Cbox\Cms\Tooling\Docs\Domain\Exclusions;
use Cbox\Cms\Tooling\Docs\Domain\Finding;
use Cbox\Cms\Tooling\Docs\Domain\Marker;
use Cbox\Cms\Tooling\Docs\Domain\PageParser;
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

const DOCS_GREETER_PAGE = 'packages/contracts/docs/greeter.md';

const DOCS_GREETER_EXAMPLE = 'examples/Unit/Greeting/GreeterTest.php';

/**
 * A marker with the fenced block of the contents below it.
 */
function docsEmbed(string $kind, string $path, string $contents, string $language = 'php'): string
{
    return "<!-- {$kind}: {$path} -->\n```{$language}\n{$contents}```\n";
}

/**
 * A page that documents the extension points and embeds the blocks.
 */
function docsPage(string $extensionPoint, string ...$blocks): string
{
    return "# A page\n\n<!-- extension-point: {$extensionPoint} -->\n\nWhat it is for, and `composer docs:check` in inline code.\n\n".implode("\n", $blocks);
}

/**
 * A fixture tree with no finding: the interface Cbox\Cms\Contracts\Greeter, its page and its example
 * test in the Unit suite.
 */
function docsTree(): string
{
    $root = ScratchDirectory::make('cbox-cms-docs-test-');

    docsWrite($root, 'phpunit.xml', DOCS_PHPUNIT);
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
 * @return list<string>
 */
function docsFindings(string $root, array $exclusions = []): array
{
    return array_map(static fn (Finding $finding): string => (string) $finding, DocsAudit::findings(LocalDocsTree::read($root), $exclusions));
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
        'packages/contracts/docs/greeter.md:7: names Cbox\Cms\Contracts\Gone as an extension point, but the inventory has no such interface, attribute class, trait, #[Command] or #[Hook] class or schema that is not #[Internal]',
        'packages/contracts/docs/greeter.md:8: names Cbox\Cms\Core\Greeting\Domain\Salute as an extension point, but the inventory excludes it: a union that nothing implements',
    ]);
});

it('reports an extension point on two pages', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/contracts/docs/greeting/again.md', docsPage('Cbox\Cms\Contracts\Greeter', docsEmbed('example', DOCS_GREETER_EXAMPLE, DOCS_GREETER_TEST)));

    expect(docsFindings($root))->toBe([
        'packages/contracts/docs/greeting/again.md:3: Cbox\Cms\Contracts\Greeter is also documented on packages/contracts/docs/greeter.md:3; every extension point is on exactly one page',
    ]);
});

it('reports a page without an example', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/core/src/Greeting/Domain/Farewell.php', docsPhp('Cbox\Cms\Core\Greeting\Domain', 'interface Farewell {}'));
    docsWrite($root, 'packages/core/docs/farewell.md', docsPage('Cbox\Cms\Core\Greeting\Domain\Farewell'));

    expect(docsFindings($root))->toBe([
        'packages/core/docs/farewell.md:1: the page has no example; add <!-- example: <repo-relative path> --> with the fenced *Test.php below it',
    ]);
});

it('reports an example block that differs from its file', function (): void {
    $root = docsTree();
    docsWrite($root, DOCS_GREETER_PAGE, docsPage('Cbox\Cms\Contracts\Greeter', docsEmbed('example', DOCS_GREETER_EXAMPLE, str_replace('Ada', 'Grace', DOCS_GREETER_TEST))));

    expect(docsFindings($root))->toBe([
        'packages/contracts/docs/greeter.md:7: the fenced block differs from examples/Unit/Greeting/GreeterTest.php; embed the file byte for byte',
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
        'packages/contracts/docs/greeter.md:18: examples/Unit/Greeting/MissingTest.php does not exist',
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
        'packages/contracts/docs/greeter.md:18: examples/Browser/Greeting/GreeterPageTest.php is in none of the gate-5 suites of phpunit.xml (Unit), so it never runs',
        'packages/contracts/docs/greeter.md:29: examples/Unit/Greeting/Greeting.php is not a *Test.php; embed a support file or fixture with <!-- example-file: <path> -->',
    ]);
});

it('reports an example file without an assertion', function (): void {
    $root = docsTree();
    $test = "<?php\n\ndeclare(strict_types=1);\n\nit('greets by name', function (): void {\n    greet('Ada');\n});\n";
    docsWrite($root, DOCS_GREETER_EXAMPLE, $test);
    docsWrite($root, DOCS_GREETER_PAGE, docsPage('Cbox\Cms\Contracts\Greeter', docsEmbed('example', DOCS_GREETER_EXAMPLE, $test)));

    expect(docsFindings($root))->toBe([
        'packages/contracts/docs/greeter.md:7: examples/Unit/Greeting/GreeterTest.php has no assertion: it calls neither expect() nor an assert method and uses no trait of the inventory',
    ]);
});

it('takes an assert method, or the use of a trait of the inventory as a shared contract suite\'s test class does, as an assertion', function (): void {
    $root = docsTree();
    docsWrite($root, 'packages/testkit/src/Greeting/GreeterContract.php', docsPhp('Cbox\Cms\Testkit\Greeting', 'trait GreeterContract {}'));
    $suite = docsPhp('Examples\Unit\Greeting', "final class SuiteTest extends TestCase\n{\n    use GreeterContract;\n}", "use Cbox\\Cms\\Testkit\\Greeting\\GreeterContract;\nuse PHPUnit\\Framework\\TestCase;\n\n");
    $asserting = "<?php\n\ndeclare(strict_types=1);\n\nit('greets by name', function (): void {\n    \$this->assertSame('Hello, Ada', 'Hello, Ada');\n});\n";
    docsWrite($root, 'examples/Unit/Greeting/SuiteTest.php', $suite);
    docsWrite($root, 'examples/Unit/Greeting/AssertingTest.php', $asserting);
    docsWrite($root, 'packages/testkit/docs/greeter-contract.md', docsPage(
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
        'packages/contracts/docs/greeter.md:18: the fenced block differs from examples/Unit/Greeting/greeting.yaml; embed the file byte for byte',
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
        'packages/contracts/docs/greeter.md:18: no example test on this page mentions PoliteGreeter, so nothing shows examples/Unit/Greeting/PoliteGreeter.php in use',
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
        'packages/contracts/docs/greeter.md:20: the fenced block is no checked embed; put a command in inline code, and embed a file with <!-- example: <path> --> or <!-- example-file: <path> --> on the line above',
        'packages/contracts/docs/greeter.md:24: <!-- example-file: examples/Unit/Greeting/GreeterTest.php --> is not followed immediately by a fenced block',
        'packages/contracts/docs/greeter.md:26: the fenced block is no checked embed; put a command in inline code, and embed a file with <!-- example: <path> --> or <!-- example-file: <path> --> on the line above',
        'packages/contracts/docs/greeter.md:26: the fenced block is not closed',
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
        'packages/contracts/docs/greeter.md:18: ../Greeting/GreeterTest.php is not a repo-relative path',
        'packages/contracts/docs/greeter.md:29: /examples/Unit/Greeting/GreeterTest.php is not a repo-relative path',
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
        'Cbox\Cms\Contracts\Gone: excluded from the inventory of extension points, but packages/*/src and packages/*/resources/schemas have no such interface, attribute class, trait, #[Command] or #[Hook] class or schema that is not #[Internal]; remove the stale exclusion',
    ]);
});

it('gives every exclusion of the Docs module a reason, and each names something the inventory rule finds in this repository', function (): void {
    $names = array_map(static fn (Exclusion $exclusion): string => $exclusion->name, Exclusions::all());
    $findings = array_map(static fn (Finding $finding): string => (string) $finding, DocsAudit::findings(LocalDocsTree::read(Phpstan::root()), Exclusions::all()));

    expect($names)->toBe([
        ClaimResult::class,
        Stable::class,
        Experimental::class,
        Internal::class,
        RealPostgres::class,
        RealValkey::class,
    ])
        ->and(array_filter(Exclusions::all(), static fn (Exclusion $exclusion): bool => ! $exclusion->hasReason()))->toBe([])
        ->and(array_values(array_filter($findings, static fn (string $finding): bool => array_any($names, static fn (string $name): bool => str_starts_with($finding, $name.':')))))->toBe([]);
});

it('finds in this repository nothing but undocumented extension points, and nothing for the blueprint schema and its page', function (): void {
    $findings = array_map(static fn (Finding $finding): string => (string) $finding, DocsAudit::findings(LocalDocsTree::read(Phpstan::root()), Exclusions::all()));

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

it('fails on a copy of this repository with one new public interface in the contracts, and names it undocumented', function (): void {
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

    foreach ([...(glob($repository.'/packages/*/src', GLOB_ONLYDIR) ?: []), ...(glob($repository.'/packages/*/docs', GLOB_ONLYDIR) ?: []), ...(glob($repository.'/packages/*/resources', GLOB_ONLYDIR) ?: []), $repository.'/examples', $repository.'/phpunit.xml'] as $path) {
        $copy($path, $scratch.substr($path, strlen($repository)));
    }

    foreach (LocalDocsTree::read($repository)->pages as $page) {
        foreach ($page->markers as $marker) {
            if ($marker->kind->embeds() && is_file($repository.'/'.$marker->target)) {
                $copy($repository.'/'.$marker->target, $scratch.'/'.$marker->target);
            }
        }
    }

    [$before, $beforeOutput] = runDocsCheck('--root='.$scratch);

    docsWrite($scratch, 'packages/contracts/src/Salutation.php', docsPhp('Cbox\Cms\Contracts', "#[Experimental]\ninterface Salutation {}", "use Cbox\\Cms\\Contracts\\Attributes\\Experimental;\n\n"));
    [$exitCode, $output, $errors] = runDocsCheck('--root='.$scratch);

    ScratchDirectory::delete($scratch);

    expect(explode("\n", $beforeOutput))->not->toContain('Cbox\Cms\Contracts\Salutation: undocumented')
        ->and($before)->toBe(1)
        ->and($exitCode)->toBe(1)
        ->and(explode("\n", $output))->toContain('Cbox\Cms\Contracts\Salutation: undocumented')
        ->and($errors)->toContain('docs:check:')
        ->and(is_dir($scratch))->toBeFalse();
});

it('exits 2 on a usage error and 1 on a root that is not a directory', function (): void {
    [$usage, , $usageErrors] = runDocsCheck('--fix');
    [$missing, , $missingErrors] = runDocsCheck('--root=/nonexistent/cbox-cms-docs');

    expect($usage)->toBe(2)
        ->and($usageErrors)->toContain('Usage: php tools/bin/docs-check.php [--root=<dir>]')
        ->and($missing)->toBe(1)
        ->and($missingErrors)->toContain('/nonexistent/cbox-cms-docs is not a directory.');
});
