<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Schema;

use Cbox\Cms\Generators\Schema\Boundary\YamlErrorLine;
use Symfony\Component\Yaml\Exception\ParseException;

/*
 * The line a blueprint file's YAML error is reported on. symfony/yaml 8 counts from the end of a
 * sequence item inside it; the reader names the line the error is on.
 */

it('finds the line of the snippet at or before the reported line', function (int $reported, string $snippet, int $line): void {
    $contents = "fields:\n  - handle: title\n    label: Title\n    label: Title\n    type: text\n  - handle: body\n";

    expect(YamlErrorLine::of(new ParseException('Duplicate key "label" detected.', $reported, $snippet), $contents))->toBe($line);
})->with([
    'a line past the item' => [7, 'label: Title', 4],
    'the right line' => [4, 'label: Title', 4],
    'a line with a sequence dash' => [9, '- handle: body', 6],
    'a dash only' => [2, 'handle: title', 2],
    'a snippet on no line' => [5, 'label: Other', 5],
    'an empty snippet' => [3, '', 3],
    'a line beyond the file' => [40, 'type: text', 5],
]);

it('keeps the reported line when the error has no snippet or no line', function (): void {
    expect(YamlErrorLine::of(new ParseException('Found unknown escape character "\q".', 3), "a: 1\nb: 2\nc: \"\\q\"\n"))->toBe(3)
        ->and(YamlErrorLine::of(new ParseException('Malformed inline YAML string.', -1, 'c: 1'), "c: 1\n"))->toBe(-1);
});
