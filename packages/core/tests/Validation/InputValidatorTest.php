<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\Validation;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Ids\TypeId;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\TypeValidator;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use Override;
use stdClass;

/*
 * The kernel's input validator (PRD 11.8, 11.12 "Hvor statiske typer slutter") on rules built by
 * hand: the shape of the input and its extension namespaces, presence at each stage, keys the type
 * does not have, JSON decoded to objects, the bound on the number of errors, and the structure of
 * Portable Text. tests/Feature/Validation/WorkbenchValidatorsTest.php covers every rule of every
 * field type through the workbench's generated validators.
 */

function field(string $handle, Presence $presence, Rule ...$rules): FieldRules
{
    return new FieldRules(new FieldHandle($handle), $presence, array_values($rules));
}

function textRules(): TypeRules
{
    return new TypeRules(
        [field('title', Presence::Required, new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['5']))],
        [
            new ExtensionRules(new FieldNamespace('app'), [
                field('tax_code', Presence::RequiredOnRelease, new Rule(RuleName::String)),
                field('title', Presence::Optional, new Rule(RuleName::Integer)),
            ]),
        ],
    );
}

/**
 * The errors of a report as `path: code`.
 *
 * @return list<string>
 */
function found(ValidationReport $report): array
{
    return array_map(static fn (CatalogError $error): string => ($error->path?->toString() ?? '(input)').': '.$error->code->value, $report->errors);
}

function portableText(): TypeRules
{
    return new TypeRules([field(
        'body',
        Presence::Optional,
        new Rule(RuleName::PortableText),
        new Rule(RuleName::Styles, ['normal']),
        new Rule(RuleName::Marks, ['strong']),
        new Rule(RuleName::Lists, ['bullet']),
        new Rule(RuleName::Links, ['url']),
    )]);
}

/**
 * A valid block with one span, changed by $changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function block(string $key, array $changes = []): array
{
    return [...['_type' => 'block', '_key' => $key, 'style' => 'normal', 'children' => [['_type' => 'span', '_key' => $key.'s', 'text' => 'Hello', 'marks' => ['strong']]]], ...$changes];
}

it('passes input that meets the rules, and needs an extension field only on release', function (): void {
    $validator = new InputValidator;
    $input = ['title' => 'Short', 'ext' => ['app' => ['title' => 3]]];

    expect($validator->validate(textRules(), $input)->passed())->toBeTrue()
        ->and(found($validator->validate(textRules(), $input, ValidationStage::Release)))->toBe(['ext.app.tax_code: validation_required'])
        ->and($validator->validate(textRules(), [...$input, 'ext' => ['app' => ['title' => 3, 'tax_code' => 'DK']]], ValidationStage::Release)->passed())->toBeTrue();
});

it('validates extension fields in their namespace, apart from the owner\'s field of the same handle', function (): void {
    $report = new InputValidator()->validate(textRules(), ['title' => 'Short', 'ext' => ['app' => ['title' => 'text']]]);

    expect(found($report))->toBe(['ext.app.title: validation_wrong_type']);
});

it('reads input decoded to objects as it reads arrays', function (): void {
    $input = json_decode('{"title": "Far too long", "ext": {"app": {"title": 1}}}');

    expect(found(new InputValidator()->validate(textRules(), $input)))->toBe(['title: validation_too_long']);
});

it('refuses input that is not an object, and an ext that is not an object of namespaces', function (mixed $input, array $expected): void {
    expect(found(new InputValidator()->validate(textRules(), $input)))->toBe($expected);
})->with([
    'a list' => [['a', 'b'], ['(input): validation_wrong_type']],
    'text' => ['title', ['(input): validation_wrong_type']],
    'ext as text' => [['title' => 'ok', 'ext' => 'app'], ['ext: validation_wrong_type']],
    'a namespace as a list' => [['title' => 'ok', 'ext' => ['app' => [1, 2]]], ['ext.app: validation_wrong_type']],
    'ext as null' => [['title' => 'ok', 'ext' => null], []],
    'a namespace as null' => [['title' => 'ok', 'ext' => ['app' => null]], []],
    'an empty object' => [[], ['title: validation_required']],
]);

it('names an unknown field, and an unknown namespace, where it is given', function (): void {
    $report = new InputValidator()->validate(textRules(), ['title' => 'ok', 'colour' => 'red', 'ext' => ['acme' => ['a' => 1], 'app' => ['size' => 1]]]);

    expect(found($report))->toBe([
        'colour: validation_unknown_field',
        'ext.app.size: validation_unknown_field',
        'ext.acme: validation_unknown_field',
    ]);
});

it('reports a key a path cannot name at its object, with the key in the message', function (): void {
    $report = new InputValidator()->validate(textRules(), ['title' => 'ok', 'bad-key' => 1, 7 => 2]);

    expect(found($report))->toBe(['(input): validation_unknown_field', '(input): validation_unknown_field'])
        ->and($report->errors[0]->message)->toBe('has the key "bad-key", which is not known: there is no such field here.')
        ->and($report->errors[1]->message)->toBe('has the key "7", which is not known: there is no such field here.');
});

it('starts every path at the path of the input in the command', function (): void {
    $report = new InputValidator()->validate(textRules(), ['ext' => ['app' => ['title' => 'x']]], ValidationStage::Release, new FieldPath('fields'));

    expect(found($report))->toBe(['fields.title: validation_required', 'fields.ext.app.tax_code: validation_required', 'fields.ext.app.title: validation_wrong_type']);
});

it('treats a null value as a value that is left out', function (): void {
    expect(found(new InputValidator()->validate(textRules(), ['title' => null])))->toBe(['title: validation_required']);
});

it('validates with the rules of a generated validator', function (): void {
    $validator = new readonly class implements TypeValidator
    {
        #[Override]
        public function type(): TypeId
        {
            return TypeId::fromString('0198d2a4-5c3e-7a41-9b2f-3c8e1f6a7d20');
        }

        #[Override]
        public function rules(): TypeRules
        {
            return textRules();
        }
    };

    expect(found(new InputValidator()->validateWith($validator, ['title' => 'Too long'], ValidationStage::Release, new FieldPath('fields'))))
        ->toBe(['fields.title: validation_too_long', 'fields.ext.app.tax_code: validation_required']);
});

it('stops at the most errors a report holds', function (): void {
    $rules = new TypeRules([new FieldRules(new FieldHandle('items'), Presence::Optional, [new Rule(RuleName::List)], [field('value', Presence::Required, new Rule(RuleName::Integer))])]);
    $report = new InputValidator()->validate($rules, ['items' => array_fill(0, 150, ['value' => 'x'])]);

    expect($report->errors)->toHaveCount(InputValidator::MAX_ERRORS)
        ->and(found($report)[99])->toBe('items[99].value: validation_wrong_type');
});

it('does not check the items of a list with more items than its field allows', function (): void {
    $rules = new TypeRules([field('tags', Presence::Optional, new Rule(RuleName::List), new Rule(RuleName::ItemsIn, ['a']), new Rule(RuleName::MaxItems, ['1']))]);

    expect(found(new InputValidator()->validate($rules, ['tags' => ['x', 'y']])))->toBe(['tags: validation_too_many_items']);
});

it('refuses text a column cannot store', function (string $text): void {
    $rules = new TypeRules([field('title', Presence::Optional, new Rule(RuleName::String))]);

    expect(found(new InputValidator()->validate($rules, ['title' => $text])))->toBe(['title: validation_wrong_type']);
})->with([
    'invalid UTF-8' => ["\xC3\x28"],
    'the character U+0000' => ["a\0b"],
]);

it('counts characters, not bytes', function (): void {
    $rules = new TypeRules([field('title', Presence::Optional, new Rule(RuleName::String), new Rule(RuleName::MinLength, ['3']), new Rule(RuleName::MaxLength, ['3']))]);

    expect(new InputValidator()->validate($rules, ['title' => 'æøå'])->passed())->toBeTrue();
});

it('compares decimals by value and dates as instants', function (): void {
    $rules = new TypeRules([
        field('price', Presence::Optional, new Rule(RuleName::Decimal, ['6', '2']), new Rule(RuleName::Min, ['0.5']), new Rule(RuleName::Max, ['10'])),
        field('at', Presence::Optional, new Rule(RuleName::Datetime), new Rule(RuleName::Max, ['2026-01-01T00:00:00Z'])),
    ]);
    $validator = new InputValidator;

    expect($validator->validate($rules, ['price' => '0010.00', 'at' => '2026-01-01T01:00:00+01:00'])->passed())->toBeTrue()
        ->and(found($validator->validate($rules, ['price' => '10.01', 'at' => '2026-01-01T00:59:59-00:01'])))->toBe(['price: validation_above_maximum', 'at: validation_above_maximum'])
        ->and(found($validator->validate($rules, ['price' => '0.49'])))->toBe(['price: validation_below_minimum']);
});

it('passes valid Portable Text', function (): void {
    $document = [
        block('a'),
        block('b', ['listItem' => 'bullet', 'level' => 2, 'markDefs' => [['_type' => 'link', '_key' => 'l1', 'href' => 'https://example.com/']], 'children' => [['_type' => 'span', '_key' => 's', 'text' => 'Link', 'marks' => ['l1', 'strong']]]]),
        ['_type' => 'block', '_key' => 'c', 'children' => [['_type' => 'span', '_key' => 's', 'text' => '', 'extra' => true]], 'renderer' => 'ignored'],
    ];

    expect(new InputValidator()->validate(portableText(), ['body' => $document])->passed())->toBeTrue();
});

it('refuses Portable Text whose structure is broken', function (mixed $document, array $expected): void {
    expect(found(new InputValidator()->validate(portableText(), ['body' => $document])))->toBe($expected);
})->with([
    'not a list' => [['_type' => 'block'], ['body: validation_wrong_type']],
    'a block that is not an object' => [['text'], ['body[0]: validation_invalid_rich_text']],
    'a block of another type' => [[block('a', ['_type' => 'image'])], ['body[0]._type: validation_invalid_rich_text']],
    'a block without a key' => [[block('a', ['_key' => ''])], ['body[0]._key: validation_invalid_rich_text']],
    'two blocks with one key' => [[block('a'), block('a')], ['body[1]._key: validation_invalid_rich_text']],
    'a style that is not text' => [[block('a', ['style' => 3])], ['body[0].style: validation_invalid_rich_text']],
    'a level without a list item' => [[block('a', ['level' => 1])], ['body[0].level: validation_invalid_rich_text']],
    'a level of 0' => [[block('a', ['listItem' => 'bullet', 'level' => 0])], ['body[0].level: validation_invalid_rich_text']],
    'no children' => [[block('a', ['children' => []])], ['body[0].children: validation_invalid_rich_text']],
    'children as an object' => [[block('a', ['children' => ['_type' => 'span']])], ['body[0].children: validation_invalid_rich_text']],
    'a child that is not a span' => [[block('a', ['children' => [['_type' => 'image', '_key' => 'x']]])], ['body[0].children[0]: validation_invalid_rich_text']],
    'two spans with one key' => [[block('a', ['children' => [['_type' => 'span', '_key' => 'x', 'text' => 'a'], ['_type' => 'span', '_key' => 'x', 'text' => 'b']]])], ['body[0].children[1]._key: validation_invalid_rich_text']],
    'a span without text' => [[block('a', ['children' => [['_type' => 'span', '_key' => 'x']]])], ['body[0].children[0].text: validation_invalid_rich_text']],
    'marks as text' => [[block('a', ['children' => [['_type' => 'span', '_key' => 'x', 'text' => 'a', 'marks' => 'strong']]])], ['body[0].children[0].marks: validation_invalid_rich_text']],
    'a mark that is not text' => [[block('a', ['children' => [['_type' => 'span', '_key' => 'x', 'text' => 'a', 'marks' => [1]]]])], ['body[0].children[0].marks[0]: validation_invalid_rich_text']],
    'mark definitions as text' => [[block('a', ['markDefs' => 'link'])], ['body[0].markDefs: validation_invalid_rich_text']],
    'a mark definition that is not an object' => [[block('a', ['markDefs' => ['link']])], ['body[0].markDefs[0]: validation_invalid_rich_text']],
    'a mark definition without a type' => [[block('a', ['markDefs' => [['_key' => 'l']]])], ['body[0].markDefs[0]._type: validation_invalid_rich_text']],
    'two mark definitions with one key' => [[block('a', ['markDefs' => [['_type' => 'link', '_key' => 'l', 'href' => 'https://a.example/'], ['_type' => 'link', '_key' => 'l', 'href' => 'https://b.example/']]])], ['body[0].markDefs[1]._key: validation_invalid_rich_text']],
    'a link that is not a URL' => [[block('a', ['markDefs' => [['_type' => 'link', '_key' => 'l', 'href' => 'javascript:alert(1)']]])], ['body[0].markDefs[0].href: validation_invalid_format']],
    'errors in two blocks' => [[block('a', ['_key' => 3]), block('b', ['children' => null])], ['body[0]._key: validation_invalid_rich_text', 'body[1].children: validation_invalid_rich_text']],
]);

it('refuses a style, mark, list or link the field does not allow', function (string $member, mixed $value, string $path): void {
    expect(found(new InputValidator()->validate(portableText(), ['body' => [block('a', [$member => $value])]])))->toBe([$path.': validation_rich_text_not_allowed']);
})->with([
    'a style' => ['style', 'h2', 'body[0].style'],
    'a list kind' => ['listItem', 'number', 'body[0].listItem'],
    'a decorator' => ['children', [['_type' => 'span', '_key' => 'x', 'text' => 'a', 'marks' => ['em']]], 'body[0].children[0].marks[0]'],
    'a mark definition of another kind' => ['markDefs', [['_type' => 'internalLink', '_key' => 'l']], 'body[0].markDefs[0]._type'],
]);

it('allows every value of a rich text rule the field does not have', function (): void {
    $rules = new TypeRules([field('body', Presence::Optional, new Rule(RuleName::PortableText))]);
    $document = [block('a', ['style' => 'anything', 'listItem' => 'any', 'markDefs' => [['_type' => 'link', '_key' => 'l', 'href' => 'http://example.com']], 'children' => [['_type' => 'span', '_key' => 's', 'text' => 'a', 'marks' => ['any']]]])];

    expect(new InputValidator()->validate($rules, ['body' => $document])->passed())->toBeTrue();
});

it('refuses links when the field allows none', function (): void {
    $rules = new TypeRules([field('body', Presence::Optional, new Rule(RuleName::PortableText), new Rule(RuleName::Links, []))]);
    $report = new InputValidator()->validate($rules, ['body' => [block('a', ['markDefs' => [['_type' => 'link', '_key' => 'l', 'href' => 'https://example.com/']]])]]);

    expect(found($report))->toBe(['body[0].markDefs[0]._type: validation_rich_text_not_allowed'])
        ->and($report->errors[0]->message)->toBe('is a mark definition the field does not allow; it allows none.');
});

it('reads a group decoded to an object', function (): void {
    $rules = new TypeRules([new FieldRules(new FieldHandle('supplier'), Presence::Optional, [new Rule(RuleName::Object)], [field('name', Presence::Required, new Rule(RuleName::String))])]);
    $supplier = new stdClass;
    $supplier->name = 'Acme';

    expect(new InputValidator()->validate($rules, ['supplier' => $supplier])->passed())->toBeTrue()
        ->and(found(new InputValidator()->validate($rules, ['supplier' => new stdClass])))->toBe(['supplier.name: validation_required']);
});
