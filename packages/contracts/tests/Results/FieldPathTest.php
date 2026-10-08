<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Results;

use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Results\InvalidWriteResult;
use Cbox\Cms\Contracts\Results\ItemKey;
use RuntimeException;

/*
 * A field path read back from the form toString() writes, as a problem details document carries it
 * (PRD 6.1, 8.8): names joined by dots, list indexes in brackets and the keys of list items in
 * brackets after a hash (PRD 11.10, decision D3 of the editing experience proposal). The examples
 * are the shared file packages/contracts/resources/field-paths.json, which the panel's TypeScript
 * twin reads in js/panel/tests/forms/field-path.test.ts, so both parsers are held to the same
 * grammar on the same input.
 */

const FIELD_PATHS = __DIR__.'/../../resources/field-paths.json';

/**
 * The entries of one list of the shared file.
 *
 * @return list<array<array-key, mixed>>
 */
function fieldPathEntries(string $list): array
{
    $contents = file_get_contents(FIELD_PATHS);

    if ($contents === false) {
        throw new RuntimeException('Cannot read '.FIELD_PATHS.'.');
    }

    $document = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    $entries = is_array($document) ? $document[$list] ?? null : null;

    if (! is_array($entries)) {
        throw new RuntimeException(sprintf('%s has no list "%s".', FIELD_PATHS, $list));
    }

    $read = [];

    foreach ($entries as $entry) {
        if (! is_array($entry)) {
            throw new RuntimeException(sprintf('An entry of "%s" in %s is not an object.', $list, FIELD_PATHS));
        }

        $read[] = $entry;
    }

    return $read;
}

/**
 * A member of an entry of the shared file that is text.
 *
 * @param  array<array-key, mixed>  $entry
 */
function fieldPathText(array $entry, string $key): string
{
    $value = $entry[$key] ?? null;

    if (! is_string($value)) {
        throw new RuntimeException(sprintf('An entry of %s has no text "%s".', FIELD_PATHS, $key));
    }

    return $value;
}

/**
 * One segment of the shared file: {"name": ...}, {"index": ...} or {"key": ...}.
 */
function fieldPathSegment(mixed $segment): string|int|ItemKey
{
    if (is_array($segment)) {
        if (is_string($segment['name'] ?? null)) {
            return $segment['name'];
        }

        if (is_int($segment['index'] ?? null)) {
            return $segment['index'];
        }

        if (is_string($segment['key'] ?? null)) {
            return new ItemKey($segment['key']);
        }
    }

    throw new RuntimeException(sprintf('A segment in %s is not a name, an index or a key.', FIELD_PATHS));
}

/**
 * The paths of the shared file: the written form and the segments it stands for, per entry.
 *
 * @return array<string, array{string, non-empty-list<string|int|ItemKey>}>
 */
function fieldPathCases(): array
{
    $cases = [];

    foreach (fieldPathEntries('paths') as $entry) {
        $segments = $entry['segments'] ?? null;

        if (! is_array($segments) || $segments === []) {
            throw new RuntimeException(sprintf('The path "%s" in %s has no segments.', fieldPathText($entry, 'written'), FIELD_PATHS));
        }

        $cases[fieldPathText($entry, 'why')] = [
            fieldPathText($entry, 'written'),
            array_values(array_map(fieldPathSegment(...), $segments)),
        ];
    }

    return $cases;
}

/**
 * The strings of the shared file that no parser reads, per entry.
 *
 * @return array<string, array{string}>
 */
function fieldPathRefusals(): array
{
    $cases = [];

    foreach (fieldPathEntries('refused') as $entry) {
        $cases[fieldPathText($entry, 'why')] = [fieldPathText($entry, 'written')];
    }

    return $cases;
}

it('reads the form toString() writes back into the same path', function (string $written, array $segments): void {
    $path = FieldPath::fromString($written);

    expect($path->segments)->toEqual($segments)
        ->and($path->toString())->toBe($written)
        ->and($path->equals(FieldPath::fromString($written)))->toBeTrue();
})->with(fieldPathCases());

it('refuses a string that is not a path', function (string $written): void {
    expect(static fn (): FieldPath => FieldPath::fromString($written))
        ->toThrow(InvalidWriteResult::class, 'A field path is a name followed by names after dots, indexes in brackets and item keys in brackets after a hash, such as "fields.body[#k3f9].heading", got "');
})->with(fieldPathRefusals());

it('writes a key segment as the key after a hash, in brackets', function (): void {
    $path = new FieldPath('fields', 'body', new ItemKey('k3f9'), 'heading');

    expect($path->toString())->toBe('fields.body[#k3f9].heading')
        ->and($path->segments)->toEqual(['fields', 'body', new ItemKey('k3f9'), 'heading'])
        ->and(new FieldPath('fields', 'body')->then(new ItemKey('k3f9'), 'heading')->toString())
        ->toBe('fields.body[#k3f9].heading');
});

it('mixes key and index segments in one path', function (): void {
    $path = new FieldPath('fields', 'body', new ItemKey('k3f9'), 'columns', 1, new ItemKey('c-2_D'), 'text');

    expect($path->toString())->toBe('fields.body[#k3f9].columns[1][#c-2_D].text')
        ->and(FieldPath::fromString($path->toString())->equals($path))->toBeTrue();
});

it('holds two paths with the same key equal, whichever key object carries it', function (): void {
    expect(new FieldPath('body', new ItemKey('k3f9'))->equals(new FieldPath('body', new ItemKey('k3f9'))))->toBeTrue()
        ->and(new FieldPath('body', new ItemKey('k3f9'))->equals(new FieldPath('body', new ItemKey('q9b3'))))->toBeFalse()
        ->and(new FieldPath('body', new ItemKey('0'))->equals(new FieldPath('body', 0)))->toBeFalse()
        ->and(new ItemKey('k3f9')->equals(new ItemKey('k3f9')))->toBeTrue()
        ->and(new ItemKey('k3f9')->equals(new ItemKey('q9b3')))->toBeFalse();
});

it('refuses an item key that is not 1 to 64 letters, digits, underscores and hyphens', function (string $value): void {
    expect(static fn (): ItemKey => new ItemKey($value))
        ->toThrow(InvalidWriteResult::class, 'An item key of a field path is 1 to 64 letters, digits, underscores and hyphens, got "');
})->with(['', 'k.3', 'k 3', 'k#3', 'k]3', 'k[3', 'k'.str_repeat('a', 64), "k\n"]);

it('reads the largest index PHP holds, which is longer than the TypeScript twin reads', function (): void {
    expect(FieldPath::fromString('list[999999999999999999]')->segments)
        ->toBe(['list', 999_999_999_999_999_999]);
});
