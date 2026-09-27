<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Arch\ContentTypeScan;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use RuntimeException;

/*
 * The content type rule of GUARDRAILS 2.4 (tests/Arch/ContentTypesTest.php) on scratch files:
 * which handles it reads from the blueprints, which parts of the code it reads and what it
 * matches.
 */

afterEach(function (): void {
    ScratchDirectory::cleanUp();
});

it('reads the type handle, the field handles, the fields of groups and the select values of every blueprint below the directory', function (): void {
    $directory = ScratchDirectory::make();
    mkdir($directory.'/blog', 0o777, true);
    file_put_contents($directory.'/blog/post.yaml', <<<'YAML'
        blueprint: 1
        kind: type
        handle: post
        fields:
          - handle: headline
            type: text
          - handle: section
            type: select
            options:
              - value: news
                label: News
              - value: sport
                label: Sport
          - handle: credits
            type: group
            fields:
              - handle: person
                type: text
          - handle: colour
            type: acme:colour
            options:
              palette: warm
        YAML);
    file_put_contents($directory.'/extension.yml', <<<'YAML'
        blueprint: 1
        kind: extension
        fields:
          - handle: tax_code
            type: text
          - handle: headline
            type: text
        YAML);
    file_put_contents($directory.'/notes.txt', "handle: ignored\n");

    expect(ContentTypeScan::handlesBelow($directory))->toBe(['colour', 'credits', 'headline', 'news', 'person', 'post', 'section', 'sport', 'tax_code']);
});

it('refuses a schema directory that does not exist, so the rule cannot pass without handles', function (): void {
    expect(static fn (): array => ContentTypeScan::handlesBelow(ScratchDirectory::make().'/missing'))
        ->toThrow(RuntimeException::class, 'does not exist');
});

it('reads the workbench schema, whose handles the core packages must not name', function (): void {
    expect(ContentTypeScan::handlesBelow(dirname(__DIR__, 3).'/'.ContentTypeScan::SCHEMA))->toContain('article', 'title', 'body');
});

it('reports string literals, heredocs, nowdocs, interpolated strings, identifiers and qualified names at their line', function (): void {
    $code = <<<'PHP'
        <?php

        namespace Acme\Article\Domain;

        final class Reader
        {
            public const string TYPE = 'article';

            public function title(string $name): string
            {
                $html = <<<HTML
                    <h1>{$name}</h1>
                    <p class="Body"></p>
                    HTML;
                $raw = <<<'RAW'
                    the article
                    RAW;

                return "an {$name} TITLE".\Acme\Body\Text::class.$html.$raw;
            }
        }
        PHP;

    expect(ContentTypeScan::hitsIn('src/Reader.php', $code, ['article', 'title', 'body']))->toBe([
        'src/Reader.php:3: Article',
        'src/Reader.php:7: article',
        'src/Reader.php:9: title',
        'src/Reader.php:13: Body',
        'src/Reader.php:16: article',
        'src/Reader.php:19: TITLE, Body',
    ]);
});

it('leaves comments and doc blocks out, so an example in a comment is allowed', function (): void {
    $code = <<<'PHP'
        <?php

        /**
         * Reads a file such as schema/article.yaml.
         */
        final class Reader
        {
            // The title comes first.
            # The body comes last.
            /* article */
            public function read(string $body): string
            {
                return $body;
            }
        }
        PHP;

    expect(ContentTypeScan::hitsIn('src/Reader.php', $code, ['article', 'title', 'body']))->toBe([]);
});

it('matches whole words only, where a word character is a letter, a digit or an underscore', function (string $text, bool $matches): void {
    $hits = ContentTypeScan::hitsIn('src/Words.php', "<?php\n\nreturn '{$text}';\n", ['article']);

    expect($hits)->toBe($matches ? ['src/Words.php:3: '.$text] : []);
})->with([
    'the handle' => ['article', true],
    'upper case' => ['ARTICLE', true],
    'mixed case' => ['Article', true],
    'the plural' => ['articles', false],
    'a prefix' => ['particle', false],
    'with an underscore' => ['article_id', false],
    'with a digit' => ['article2', false],
]);

it('reads a file that is not PHP as text', function (): void {
    expect(ContentTypeScan::hitsIn('src/stubs/type.stub', "first\n# the Title\n", ['title']))->toBe(['src/stubs/type.stub:2: Title']);
});

it('scans the src directory of each core package and nothing else', function (): void {
    $root = ScratchDirectory::make();

    foreach ([...ContentTypeScan::PACKAGES, 'panel'] as $package) {
        mkdir("{$root}/packages/{$package}/src/Domain", 0o777, true);
        mkdir("{$root}/packages/{$package}/tests", 0o777, true);
        file_put_contents("{$root}/packages/{$package}/src/Domain/Type.php", "<?php\n\nreturn 'article';\n");
        file_put_contents("{$root}/packages/{$package}/tests/TypeTest.php", "<?php\n\nreturn 'article';\n");
    }

    $scan = ContentTypeScan::of($root, ['article', 'article']);

    expect($scan->handles)->toBe(['article'])
        ->and($scan->files)->toBe([
            'packages/cli/src/Domain/Type.php',
            'packages/contracts/src/Domain/Type.php',
            'packages/core/src/Domain/Type.php',
            'packages/generators/src/Domain/Type.php',
            'packages/http/src/Domain/Type.php',
            'packages/testkit/src/Domain/Type.php',
        ])
        ->and($scan->hits)->toBe(array_map(static fn (string $file): string => $file.':3: article', $scan->files));
});
