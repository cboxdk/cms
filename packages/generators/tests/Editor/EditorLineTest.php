<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Editor;

use Cbox\Cms\Generators\Editor\Domain\EditorLine;
use Cbox\Cms\Generators\Generation\Domain\GenerationFailed;

/*
 * The editor line of a blueprint file (blueprint decision 3): first, once, and every other byte
 * of the file kept.
 */

const EDITOR_SCHEMA = '../../vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json';

const EDITOR_LINE = '# yaml-language-server: $schema='.EDITOR_SCHEMA;

const EDITOR_BODY = <<<'YAML'
    # The page type.
    blueprint: 1
    kind: type
    description: |
      # yaml-language-server: $schema=inside/a/block/scalar.json
      A page.
    # yaml-language-server: $schema=a/comment/after/the/content.json

    YAML;

it('is the yaml-language-server comment with the path', function (): void {
    expect(new EditorLine(EDITOR_SCHEMA)->line)->toBe(EDITOR_LINE);
});

it('adds the line first and keeps every other byte', function (string $contents): void {
    expect(new EditorLine(EDITOR_SCHEMA)->apply($contents))->toBe(EDITOR_LINE."\n".$contents);
})->with([
    'a file with a comment first' => [EDITOR_BODY],
    'a file with content first' => ["blueprint: 1\nkind: type\n"],
    'a file without a line ending at its end' => ['blueprint: 1'],
    'a file with a blank line first' => ["\n\nblueprint: 1\n"],
    'a file with an unrelated yaml-language-server setting' => ["# yaml-language-server: \$format.enable=false\nblueprint: 1\n"],
    'a file that mentions the line in a later comment' => ["# Run cms:schema:editor for the # yaml-language-server: \$schema= line.\nblueprint: 1\n"],
]);

it('gives an empty file the line alone', function (): void {
    expect(new EditorLine(EDITOR_SCHEMA)->apply(''))->toBe(EDITOR_LINE."\n");
});

it('replaces a wrong line instead of adding a second one', function (string $wrong): void {
    $edited = new EditorLine(EDITOR_SCHEMA)->apply($wrong."\n".EDITOR_BODY);

    expect($edited)->toBe(EDITOR_LINE."\n".EDITOR_BODY)
        ->and(substr_count($edited, '# yaml-language-server: $schema=../../'))->toBe(1);
})->with([
    'another path' => ['# yaml-language-server: $schema=../packages/contracts/resources/schemas/blueprint.v1.json'],
    'a URL' => ['# yaml-language-server: $schema=https://example.com/blueprint.json'],
    'other spacing' => ['#yaml-language-server :  $schema=blueprint.v1.json'],
    'indented' => ['  # yaml-language-server: $schema=blueprint.v1.json'],
    'the right line twice' => [EDITOR_LINE."\n".EDITOR_LINE],
]);

it('moves a line of the leading block to the top, and keeps the comments around it', function (): void {
    $contents = "# The page type.\n\n# yaml-language-server: \$schema=old.json\n%YAML 1.2\n---\n# yaml-language-server: \$schema=older.json\nblueprint: 1\n";

    expect(new EditorLine(EDITOR_SCHEMA)->apply($contents))
        ->toBe(EDITOR_LINE."\n# The page type.\n\n%YAML 1.2\n---\nblueprint: 1\n");
});

it('never touches a line after the leading block', function (): void {
    $contents = "--- |\n  # yaml-language-server: \$schema=inside/a/literal.json\n";

    expect(new EditorLine(EDITOR_SCHEMA)->apply($contents))->toBe(EDITOR_LINE."\n".$contents);
});

it('keeps a byte order mark first', function (): void {
    expect(new EditorLine(EDITOR_SCHEMA)->apply("\u{FEFF}# yaml-language-server: \$schema=old.json\nblueprint: 1\n"))
        ->toBe("\u{FEFF}".EDITOR_LINE."\nblueprint: 1\n");
});

it('ends the line with CRLF in a file whose first line ends with CRLF', function (): void {
    expect(new EditorLine(EDITOR_SCHEMA)->apply("blueprint: 1\r\nkind: type\n"))->toBe(EDITOR_LINE."\r\nblueprint: 1\r\nkind: type\n")
        ->and(new EditorLine(EDITOR_SCHEMA)->apply("# yaml-language-server: \$schema=old.json\r\nblueprint: 1\r\n"))->toBe(EDITOR_LINE."\r\nblueprint: 1\r\n");
});

it('changes nothing the second time', function (string $contents): void {
    $line = new EditorLine(EDITOR_SCHEMA);
    $once = $line->apply($contents);

    expect($line->apply($once))->toBe($once);
})->with([
    'a file without the line' => [EDITOR_BODY],
    'a file with a wrong line' => ["# yaml-language-server: \$schema=old.json\r\n".EDITOR_BODY],
    'an empty file' => [''],
    'a file with a byte order mark' => ["\u{FEFF}blueprint: 1"],
    'a file with the line alone and no line ending' => [EDITOR_LINE],
]);

it('points from the file\'s directory to the schema', function (): void {
    expect(EditorLine::towards('/srv/app/vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json', '/srv/app/workbench/schema')->line)->toBe(EDITOR_LINE)
        ->and(EditorLine::towards('/srv/app/vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json', '/srv/app/schema/shop/products')->schema)
        ->toBe('../../../vendor/cboxdk/cms-contracts/resources/schemas/blueprint.v1.json');
});

it('refuses a path that cannot be one line', function (string $schema): void {
    expect(static fn (): EditorLine => new EditorLine($schema))
        ->toThrow(GenerationFailed::class, '[generate_invalid_config] ');
})->with([
    'empty' => [''],
    'a line feed' => ["../schema\n.json"],
    'a carriage return' => ["../schema\r.json"],
]);
