<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Results;

use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\InvalidWriteResult;

/*
 * A field path read back from the form toString() writes, as a problem details document carries it
 * (PRD 6.1, 8.8): names joined by dots and list indexes in brackets.
 */

it('reads the form toString() writes back into the same path', function (string $written, FieldPath $path): void {
    expect(FieldPath::fromString($written))->toEqual($path)
        ->and(FieldPath::fromString($written)->equals($path))->toBeTrue()
        ->and($path->toString())->toBe($written);
})->with([
    'a name' => ['title', new FieldPath('title')],
    'names' => ['fields.ext.app.tax_code', new FieldPath('fields', 'ext', 'app', 'tax_code')],
    'indexes' => ['blocks[2].children[0].text', new FieldPath('blocks', 2, 'children', 0, 'text')],
    'indexes in a row' => ['matrix[10][0]', new FieldPath('matrix', 10, 0)],
    'underscores and digits' => ['_a1.B_2', new FieldPath('_a1', 'B_2')],
    'the largest index' => ['list[999999999999999999]', new FieldPath('list', 999_999_999_999_999_999)],
]);

it('refuses a string that is not a path', function (string $value): void {
    expect(static fn (): FieldPath => FieldPath::fromString($value))
        ->toThrow(InvalidWriteResult::class, 'A field path is a name followed by names after dots and indexes in brackets, such as "blocks[2].text", got "');
})->with(['', '[0]', '0', '.a', 'a.', 'a..b', 'a[', 'a[]', 'a[01]', 'a[-1]', 'a[1000000000000000000]', 'a b', 'a.1', 'a[0]b', "a\n"]);
