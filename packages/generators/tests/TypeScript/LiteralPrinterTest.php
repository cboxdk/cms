<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\TypeScript;

use Cbox\Cms\Generators\Codec\Domain\TypeScript\ArrayLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\BooleanLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Literal;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\LiteralPrinter;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\NumberLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\ObjectLiteral;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Property;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\Reference;
use Cbox\Cms\Generators\Codec\Domain\TypeScript\StringLiteral;
use Cbox\Cms\Tests\Support\Node;
use Symfony\Component\Process\Process;

/*
 * The printer of the literals the TypeScript validators hold their rules in (PRD 11.12): it prints
 * each the way Prettier prints it with the shared configuration, so a generated module passes
 * `prettier --check` unchanged. Every constant printed here goes through Prettier as well.
 */

/**
 * @param  array<string, Literal>  $properties
 */
function objectOf(array $properties): ObjectLiteral
{
    return new ObjectLiteral(array_map(static fn (string $key, Literal $value): Property => new Property($key, $value), array_keys($properties), $properties));
}

/**
 * The constants of the dataset below, by name.
 *
 * @return array<string, array{Literal, string}>
 */
function printedConstants(): array
{
    $rule = static fn (string $key): ObjectLiteral => objectOf(['key' => new StringLiteral($key), 'presence' => new StringLiteral('required')]);

    return [
        'a short object on one line' => [objectOf(['kind' => new StringLiteral('text'), 'maxLength' => NumberLiteral::of(255)]), "const a: unknown = { kind: 'text', maxLength: 255 };"],
        'an empty object and an empty array' => [objectOf(['object' => new ObjectLiteral([]), 'items' => new ArrayLiteral([])]), 'const a: unknown = { object: {}, items: [] };'],
        'a long object with one property per line' => [
            objectOf(['kind' => new StringLiteral('portable_text'), 'styles' => ArrayLiteral::strings(['normal', 'h2', 'h3', 'h4', 'h5']), 'marks' => ArrayLiteral::strings(['strong', 'em'])]),
            "const a: unknown = {\n  kind: 'portable_text',\n  styles: ['normal', 'h2', 'h3', 'h4', 'h5'],\n  marks: ['strong', 'em'],\n};",
        ],
        'an array of objects of two properties, broken however short' => [new ArrayLiteral([$rule('a'), $rule('b')]), "const a: unknown = [\n  { key: 'a', presence: 'required' },\n  { key: 'b', presence: 'required' },\n];"],
        'an array of one such object on one line' => [new ArrayLiteral([$rule('a')]), "const a: unknown = [{ key: 'a', presence: 'required' }];"],
        'an array of objects of one property on one line' => [new ArrayLiteral([objectOf(['a' => NumberLiteral::of(1)]), objectOf(['b' => NumberLiteral::of(2)])]), 'const a: unknown = [{ a: 1 }, { b: 2 }];'],
        'an array of arrays of two items, broken' => [new ArrayLiteral([ArrayLiteral::strings(['a', 'b']), ArrayLiteral::strings(['c', 'd'])]), "const a: unknown = [\n  ['a', 'b'],\n  ['c', 'd'],\n];"],
        'a long array of strings with one per line' => [
            ArrayLiteral::strings(array_map(static fn (int $number): string => 'a_rather_long_value_'.$number, range(1, 5))),
            "const a: unknown = [\n  'a_rather_long_value_1',\n  'a_rather_long_value_2',\n  'a_rather_long_value_3',\n  'a_rather_long_value_4',\n  'a_rather_long_value_5',\n];",
        ],
        'a long array of numbers, filled' => [
            new ArrayLiteral(array_map(NumberLiteral::of(...), range(1000, 1024))),
            "const a: unknown = [\n  1000, 1001, 1002, 1003, 1004, 1005, 1006, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014, 1015,\n  1016, 1017, 1018, 1019, 1020, 1021, 1022, 1023, 1024,\n];",
        ],
        'a long string after a long key on the next line' => [
            objectOf(['pattern' => new StringLiteral(str_repeat('p', 90)), 'kind' => new StringLiteral('string')]),
            "const a: unknown = {\n  pattern:\n    '".str_repeat('p', 90)."',\n  kind: 'string',\n};",
        ],
        'a long string after a short key on its line' => [
            objectOf(['key' => new StringLiteral(str_repeat('k', 95)), 'kind' => new StringLiteral('string')]),
            "const a: unknown = {\n  key: '".str_repeat('k', 95)."',\n  kind: 'string',\n};",
        ],
        'a long reference after a long key on the next line' => [
            objectOf(['object' => new Reference(str_repeat('r', 95)), 'kind' => new BooleanLiteral(false)]),
            "const a: unknown = {\n  object:\n    ".str_repeat('r', 95).",\n  kind: false,\n};",
        ],
        'strings quoted as Prettier quotes them' => [
            ArrayLiteral::strings(["it's", 'say "hi"', 'back\\slash', "both ' and \"\""]),
            "const a: unknown = [\"it's\", 'say \"hi\"', 'back\\\\slash', 'both \\' and \"\"'];",
        ],
        'keys quoted when they are not identifiers' => [objectOf(['a-b' => NumberLiteral::of(1), '$ok' => new NumberLiteral('-Infinity')]), "const a: unknown = { 'a-b': 1, \$ok: -Infinity };"],
        'a head too long for its line, with the literal on the next' => [
            objectOf(['properties' => new ArrayLiteral([])]),
            'const '.str_repeat('n', 90).": unknown =\n  { properties: [] };",
        ],
    ];
}

it('prints each literal as Prettier does', function (Literal $literal, string $expected): void {
    $name = str_starts_with($expected, 'const a:') ? 'a' : str_repeat('n', 90);

    expect(implode("\n", LiteralPrinter::constant($name, 'unknown', $literal)))->toBe($expected);
})->with(printedConstants());

it('prints what Prettier leaves unchanged', function (): void {
    $source = '';

    foreach (printedConstants() as [$literal, $expected]) {
        $source .= "\n".$expected."\n";
    }

    $format = Node::withProbe('ts', ltrim($source), static fn (string $path): Process => Node::tool('prettier', ['--check', $path]));

    expect($format->getExitCode())->toBe(0, $source.$format->getOutput().$format->getErrorOutput());
});
