<?php

declare(strict_types=1);

namespace Cbox\Cms\Tests\Feature\Tooling;

use Cbox\Cms\Tests\Support\Arch\ContentTypeScan;
use Cbox\Cms\Tests\Support\Arch\Rules;
use Cbox\Cms\Tests\Support\Tooling\ScratchDirectory;
use PHPUnit\Framework\ExpectationFailedException;
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

it('reads the workbench schema, whose handles the core packages must not name and all start with the prefix', function (): void {
    $handles = ContentTypeScan::handlesBelow(dirname(__DIR__, 3).'/'.ContentTypeScan::SCHEMA);

    expect($handles)->toContain('fixture_article', 'fixture_body', 'fixture_title')
        ->and(ContentTypeScan::ordinary($handles))->toBe([]);
});

it('names the handles that do not start with the prefix, so an ordinary word cannot become a handle', function (): void {
    expect(ContentTypeScan::PREFIX)->toBe('fixture_')
        ->and(ContentTypeScan::ordinary(['fixture_title', 'title', 'body', 'fixture_', 'fixture', 'my_fixture_body', 'fixture_body', 'body']))
        ->toBe(['body', 'fixture', 'fixture_', 'my_fixture_body', 'title']);
});

it('reports every line that names a handle, in code, strings, heredocs, variables, comments and doc blocks alike', function (): void {
    $code = <<<'PHP'
        <?php

        namespace Acme\FixtureArticle\Domain;

        /**
         * Reads schema/fixture_article.yaml.
         */
        final class Reader
        {
            public const string TYPE = 'fixture_article';

            // The fixture-title comes first.
            public function read(string $fixtureBody): string
            {
                $html = <<<HTML
                    <p class="FIXTURE_BODY">{$fixtureBody}</p>
                    HTML;

                return "{$html} fixture_title".\Acme\FixtureTitle\Text::class;
            }
        }
        PHP;

    expect(ContentTypeScan::hitsIn('src/Reader.php', $code, ['fixture_article', 'fixture_title', 'fixture_body']))->toBe([
        'src/Reader.php:3: FixtureArticle',
        'src/Reader.php:6: fixture_article',
        'src/Reader.php:10: fixture_article',
        'src/Reader.php:12: fixture-title',
        'src/Reader.php:13: fixtureBody',
        'src/Reader.php:16: FIXTURE_BODY, fixtureBody',
        'src/Reader.php:19: fixture_title, FixtureTitle',
    ]);
});

it('matches a handle in any case, inside a longer name and with its underscores as any run of _ and - or none', function (string $text, ?string $hit): void {
    $hits = ContentTypeScan::hitsIn('src/Words.php', "<?php\n\nreturn '{$text}';\n", ['fixture_article']);

    expect($hits)->toBe($hit === null ? [] : ['src/Words.php:3: '.$hit]);
})->with([
    'the handle' => ['fixture_article', 'fixture_article'],
    'upper case' => ['FIXTURE_ARTICLE', 'FIXTURE_ARTICLE'],
    'studly case' => ['FixtureArticle', 'FixtureArticle'],
    'camel case' => ['fixtureArticle', 'fixtureArticle'],
    'kebab case' => ['fixture-article', 'fixture-article'],
    'a doubled separator' => ['fixture__article', 'fixture__article'],
    'the plural' => ['fixture_articles', 'fixture_article'],
    'a prefix' => ['my_fixture_article', 'fixture_article'],
    'with an id' => ['fixture_article_id', 'fixture_article'],
    'in a class name' => ['FixtureArticleReader', 'FixtureArticle'],
    'the first word alone' => ['fixture', null],
    'the second word alone' => ['article', null],
    'split by a space' => ['fixture article', null],
    'split by a dot' => ['fixture.article', null],
    'a shorter word' => ['fixture_articl', null],
]);

it('reports a handle that contains another whole, the longest first', function (): void {
    expect(ContentTypeScan::hitsIn('src/Fields.php', "<?php\n\nreturn ['fixture_body_text', 'fixture_body'];\n", ['fixture_body', 'fixture_body_text']))
        ->toBe(['src/Fields.php:3: fixture_body_text, fixture_body']);
});

it('reads a file that is not PHP as text', function (): void {
    expect(ContentTypeScan::hitsIn('src/stubs/type.stub', "first\n# the Fixture_Title\n", ['fixture_title']))->toBe(['src/stubs/type.stub:2: Fixture_Title']);
});

it('fails the rule for each handle of the workbench schema written into the src of a core package', function (): void {
    $root = ScratchDirectory::make();
    $handles = ContentTypeScan::handlesBelow(dirname(__DIR__, 3).'/'.ContentTypeScan::SCHEMA);
    mkdir($root.'/packages/core/src/Domain', 0o777, true);

    foreach ($handles as $index => $handle) {
        $studly = str_replace('_', '', ucwords($handle, '_'));
        file_put_contents("{$root}/packages/core/src/Domain/Type{$index}.php", "<?php\n\nnamespace Cbox\\Cms\\Core\\{$studly}\\Domain;\n\nreturn '{$handle}';\n");
    }

    $scan = ContentTypeScan::of($root, $handles);

    expect($handles)->not->toBe([])
        ->and($scan->hits)->toHaveCount(2 * count($handles))
        ->and(static fn () => Rules::none($scan->hits, 'The core packages may not name a type, a field or a select value of the fixture schema.'))
        ->toThrow(ExpectationFailedException::class, 'packages/core/src/Domain/Type0.php:3: ');
});

it('scans the src directory of each core package and nothing else', function (): void {
    $root = ScratchDirectory::make();

    foreach ([...ContentTypeScan::PACKAGES, 'members'] as $package) {
        mkdir("{$root}/packages/{$package}/src/Domain", 0o777, true);
        mkdir("{$root}/packages/{$package}/tests", 0o777, true);
        file_put_contents("{$root}/packages/{$package}/src/Domain/Type.php", "<?php\n\nreturn 'fixture_article';\n");
        file_put_contents("{$root}/packages/{$package}/tests/TypeTest.php", "<?php\n\nreturn 'fixture_article';\n");
    }

    $scan = ContentTypeScan::of($root, ['fixture_article', 'fixture_article']);

    expect($scan->handles)->toBe(['fixture_article'])
        ->and($scan->files)->toBe([
            'packages/cli/src/Domain/Type.php',
            'packages/contracts/src/Domain/Type.php',
            'packages/core/src/Domain/Type.php',
            'packages/generators/src/Domain/Type.php',
            'packages/http/src/Domain/Type.php',
            'packages/identity/src/Domain/Type.php',
            'packages/mcp/src/Domain/Type.php',
            'packages/panel/src/Domain/Type.php',
            'packages/testkit/src/Domain/Type.php',
        ])
        ->and($scan->hits)->toBe(array_map(static fn (string $file): string => $file.':3: fixture_article', $scan->files));
});
