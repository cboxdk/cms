<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Pipeline;

use Cbox\Cms\Contracts\Fields\BooleanValue;
use Cbox\Cms\Contracts\Fields\DateTimeValue;
use Cbox\Cms\Contracts\Fields\DateValue;
use Cbox\Cms\Contracts\Fields\DecimalValue;
use Cbox\Cms\Contracts\Fields\ExtensionFields;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldMap;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Fields\FieldValue;
use Cbox\Cms\Contracts\Fields\FieldValues;
use Cbox\Cms\Contracts\Fields\GroupValue;
use Cbox\Cms\Contracts\Fields\IntegerValue;
use Cbox\Cms\Contracts\Fields\ListValue;
use Cbox\Cms\Contracts\Fields\MapEntry;
use Cbox\Cms\Contracts\Fields\MapValue;
use Cbox\Cms\Contracts\Fields\NamedValue;
use Cbox\Cms\Contracts\Fields\NullValue;
use Cbox\Cms\Contracts\Fields\TextValue;
use Cbox\Cms\Core\Pipeline\Boundary\FieldValuesInput;
use DateTimeImmutable;
use LogicException;

/*
 * A revision's fields in the input form the kernel's InputValidator reads: JSON decoded to
 * objects, with the extension fields under `ext`.
 */

function inputField(string $handle, FieldValue $value): NamedValue
{
    return new NamedValue(new FieldHandle($handle), $value);
}

it('gives every kind of value its JSON form', function (): void {
    $fields = new FieldValues(new FieldMap(
        inputField('a_text', new TextValue('Hej')),
        inputField('b_integer', new IntegerValue(-7)),
        inputField('c_decimal', new DecimalValue('012.50')),
        inputField('d_boolean', new BooleanValue(false)),
        inputField('e_date', new DateValue('2026-09-29')),
        inputField('f_datetime', new DateTimeValue(new DateTimeImmutable('2026-09-29T14:00:00.123456+02:00'))),
        inputField('g_null', new NullValue),
        inputField('h_list', new ListValue(new TextValue('x'), new IntegerValue(1))),
        inputField('i_group', new GroupValue(new FieldMap(inputField('inner', new TextValue('y'))))),
        inputField('j_map', new MapValue(new MapEntry('_type', new TextValue('block')), new MapEntry('children', new ListValue))),
        inputField('k_empty_group', new GroupValue(new FieldMap)),
    ));

    expect(json_encode(FieldValuesInput::of($fields), JSON_THROW_ON_ERROR))->toBe(
        '{"a_text":"Hej","b_integer":-7,"c_decimal":"12.5","d_boolean":false,"e_date":"2026-09-29",'
        .'"f_datetime":"2026-09-29T12:00:00.123456Z","g_null":null,"h_list":["x",1],"i_group":{"inner":"y"},'
        .'"j_map":{"_type":"block","children":[]},"k_empty_group":{}}',
    );
});

it('puts the extension fields under ext by namespace, and leaves ext out without them', function (): void {
    $fields = new FieldValues(
        new FieldMap(inputField('title', new TextValue('A'))),
        new ExtensionFields(new FieldNamespace('erp'), new FieldMap(inputField('code', new IntegerValue(2)))),
        new ExtensionFields(new FieldNamespace('app'), new FieldMap(inputField('title', new TextValue('B')))),
    );

    expect(json_encode(FieldValuesInput::of($fields), JSON_THROW_ON_ERROR))->toBe('{"title":"A","ext":{"app":{"title":"B"},"erp":{"code":2}}}')
        ->and(json_encode(FieldValuesInput::of(new FieldValues), JSON_THROW_ON_ERROR))->toBe('{}');
});

it('refuses a kind of value it has no form for', function (): void {
    $unknown = new readonly class implements FieldValue
    {
        public function equals(FieldValue $other): bool
        {
            return $other === $this;
        }
    };

    expect(static fn (): object => FieldValuesInput::of(new FieldValues(new FieldMap(inputField('odd', $unknown)))))
        ->toThrow(LogicException::class, 'has no input form.');
});
