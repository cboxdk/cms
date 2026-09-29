<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Tests\Descriptor;

use Cbox\Cms\Generators\Descriptor\Domain\Dto\ColumnShape;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\PhpType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\TypeScriptType;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValidationRule;
use Cbox\Cms\Generators\Descriptor\Domain\Dto\ValueShape;
use Cbox\Cms\Generators\Descriptor\Domain\SqlText;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDate;
use Cbox\Cms\Generators\Schema\Domain\BlueprintDatetime;
use Cbox\Cms\Generators\Schema\Domain\DecimalBound;
use Cbox\Cms\Generators\Schema\Domain\Dto\DateOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DatetimeOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\DecimalOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\GroupRepeat;
use Cbox\Cms\Generators\Schema\Domain\Dto\IntegerOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\LongTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\RichTextOptions;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOption;
use Cbox\Cms\Generators\Schema\Domain\Dto\SelectOptions;
use Cbox\Cms\Generators\Schema\Domain\FieldOptions;
use Cbox\Cms\Generators\Schema\Domain\Handle;

/*
 * How each core field type describes its column and its value for the type descriptor (PRD 11.6,
 * 11.12), for the options the comprehensive example does not use: a field type without bounds, with
 * only an upper bound, a multiple select without item bounds, a group that occurs once and a
 * repeated group without a lower bound. The example's golden file covers the rest.
 */

/**
 * The column shape of the options over the column `"c"`, as its type and checks.
 *
 * @return array{string, list<string>}
 */
function columnOf(FieldOptions $options): array
{
    $shape = $options->describeColumn(SqlText::identifier('c'));

    return [$shape->type, $shape->checks];
}

/**
 * The value shape of the options without nested fields, as its PHP doc, its TypeScript type and
 * its rules.
 *
 * @return array{string, string, list<string>}
 */
function valueOf(FieldOptions $options): array
{
    $shape = $options->describeValue([]);

    return [
        $shape->php->doc,
        $shape->typeScript->type,
        array_map(static fn (ValidationRule $rule): string => $rule->name->value.($rule->arguments === [] ? '' : ':'.implode(',', $rule->arguments)), $shape->rules),
    ];
}

it('describes each field type\'s column and value for options the example does not use', function (FieldOptions $options, array $column, array $value): void {
    expect(columnOf($options))->toBe($column)
        ->and(valueOf($options))->toBe($value);
})->with([
    'a long text with a minimum length' => [
        new LongTextOptions(5, 100),
        ['text', ['char_length("c") >= 5', 'char_length("c") <= 100']],
        ['string', 'string', ['string', 'min_length:5', 'max_length:100']],
    ],
    'an integer without bounds' => [new IntegerOptions(null, null, null), ['bigint', []], ['int', 'number', ['integer']]],
    'an integer with only a maximum' => [new IntegerOptions(null, -5, 'pcs'), ['bigint', ['"c" <= -5']], ['int<min, -5>', 'number', ['integer', 'max:-5']]],
    'a decimal without bounds' => [new DecimalOptions(12, 3, null, null, null), ['numeric(12, 3)', []], ['numeric-string', 'string', ['decimal:12,3']]],
    'a decimal with only a maximum' => [new DecimalOptions(5, 0, null, new DecimalBound('-12'), null), ['numeric(5, 0)', ['"c" <= -12']], ['numeric-string', 'string', ['decimal:5,0', 'max:-12']]],
    'a date without bounds' => [new DateOptions(null, null), ['date', []], ['DateTimeImmutable', 'string', ['date']]],
    'a date with only a maximum' => [new DateOptions(null, new BlueprintDate('2030-06-30')), ['date', ['"c" <= \'2030-06-30\'::date']], ['DateTimeImmutable', 'string', ['date', 'max:2030-06-30']]],
    'a datetime with only a maximum' => [
        new DatetimeOptions(null, new BlueprintDatetime('2030-06-30T12:00:00+02:00')),
        ['timestamptz', ['"c" <= \'2030-06-30T12:00:00+02:00\'::timestamptz']],
        ['DateTimeImmutable', 'string', ['datetime', 'max:2030-06-30T12:00:00+02:00']],
    ],
    'a multiple select without item bounds' => [
        new SelectOptions([new SelectOption(new Handle('x'), 'X')], true, null, null),
        ['text[]', ['"c" <@ ARRAY[\'x\']::text[]']],
        ["list<'x'>", "Array<'x'>", ['list', 'distinct', 'items_in:x']],
    ],
    'a multiple select with only a maximum' => [
        new SelectOptions([new SelectOption(new Handle('x'), 'X'), new SelectOption(new Handle('y'), 'Y')], true, null, 2),
        ['text[]', ['"c" <@ ARRAY[\'x\', \'y\']::text[]', 'cardinality("c") <= 2']],
        ["list<'x'|'y'>", "Array<'x' | 'y'>", ['list', 'distinct', 'items_in:x,y', 'max_items:2']],
    ],
    'rich text that allows no styles, marks, lists or links' => [
        new RichTextOptions([], [], [], []),
        ['jsonb', ['jsonb_typeof("c") = \'array\'']],
        ['list<array<string, mixed>>', 'Array<Record<string, unknown>>', ['portable_text', 'styles', 'marks', 'lists', 'links']],
    ],
    'a group that occurs once' => [new GroupOptions([], null), ['jsonb', ['jsonb_typeof("c") = \'object\'']], ['array{}', '{  }', ['object']]],
    'a repeated group without a minimum' => [
        new GroupOptions([], new GroupRepeat(null, 7)),
        ['jsonb', ['CASE WHEN jsonb_typeof("c") = \'array\' THEN jsonb_array_length("c") BETWEEN 0 AND 7 ELSE false END']],
        ['list<array{}>', 'Array<{  }>', ['list', 'max_items:7']],
    ],
]);

it('gives the choices of a select field in the order of the file, and none for another type', function (): void {
    $options = [new SelectOption(new Handle('z'), 'Z'), new SelectOption(new Handle('a'), 'A')];

    expect(new SelectOptions($options, false, null, null)->describeValue([])->choices)->toBe($options)
        ->and(new SelectOptions($options, true, null, null)->describeValue([])->choices)->toBe($options)
        ->and(new LongTextOptions(null, 10)->describeValue([])->choices)->toBe([]);
});

it('quotes identifiers and literals for SQL, doubling the quote inside', function (): void {
    expect(SqlText::identifier('order'))->toBe('"order"')
        ->and(SqlText::identifier('a"b'))->toBe('"a""b"')
        ->and(SqlText::literal('it\'s'))->toBe("'it''s'")
        ->and(SqlText::literals(['a', 'b\'c']))->toBe("'a', 'b''c'")
        ->and(SqlText::literals([]))->toBe('');
});

it('adds null to a PHP and a TypeScript type only when the value may be null', function (): void {
    $php = new PhpType('int', 'int<0, max>');
    $typeScript = new TypeScriptType("'a' | 'b'");

    expect([$php->nullable, $php->docType(), $php->withNullable(true)->docType(), $php->withNullable(true)->native, $php->withNullable(true)->withNullable(false)->docType()])
        ->toBe([false, 'int<0, max>', 'int<0, max>|null', 'int', 'int<0, max>'])
        ->and([$typeScript->nullable, $typeScript->declaration(), $typeScript->withNullable(true)->declaration(), $typeScript->withNullable(true)->withNullable(false)->declaration()])
        ->toBe([false, "'a' | 'b'", "'a' | 'b' | null", "'a' | 'b'"])
        ->and(new ColumnShape('boolean')->checks)->toBe([])
        ->and(new ValueShape($php, $typeScript, [])->choices)->toBe([]);
});
