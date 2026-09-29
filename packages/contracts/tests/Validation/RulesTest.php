<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Validation;

use Cbox\Cms\Contracts\Errors\ErrorCode;
use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Results\FieldPath;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\InvalidRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Contracts\Validation\ValidationStage;

/*
 * The rules of the runtime validators (PRD 11.8, 11.12): a rule refuses arguments of the wrong
 * number or form, a field's rules start with one type rule and name only rules it allows, each
 * once, bounds are values of the type rule's kind, only groups have nested fields, and handles and
 * namespaces are given once. These faults are in generated code, so they throw InvalidRules.
 */

/**
 * @param  list<Rule>  $rules
 * @param  list<FieldRules>  $fields
 */
function rulesFor(string $handle, array $rules, array $fields = [], Presence $presence = Presence::Optional): FieldRules
{
    return new FieldRules(new FieldHandle($handle), $presence, $rules, $fields);
}

it('accepts the arguments of every rule in their form', function (RuleName $name, string ...$arguments): void {
    $rule = new Rule($name, array_values($arguments));

    expect($rule->name)->toBe($name)
        ->and($rule->arguments)->toBe(array_values($arguments));
})->with([
    'a type rule' => [RuleName::String],
    'distinct' => [RuleName::Distinct],
    'decimal' => [RuleName::Decimal, '10', '2'],
    'decimal of scale 0' => [RuleName::Decimal, '1', '0'],
    'decimal of the highest precision' => [RuleName::Decimal, '38', '38'],
    'min_length 0' => [RuleName::MinLength, '0'],
    'max_items' => [RuleName::MaxItems, '500'],
    'min' => [RuleName::Min, '-3'],
    'format email' => [RuleName::Format, 'email'],
    'format url' => [RuleName::Format, 'url'],
    'in' => [RuleName::In, 'a', 'b'],
    'links, none allowed' => [RuleName::Links],
]);

it('refuses arguments of the wrong number or form', function (string $message, RuleName $name, string ...$arguments): void {
    expect(fn (): Rule => new Rule($name, array_values($arguments)))->toThrow(InvalidRules::class, $message);
})->with([
    'a type rule with an argument' => ['The rule string takes 0 arguments, got 1.', RuleName::String, '1'],
    'decimal with one argument' => ['The rule decimal takes 2 arguments, got 1.', RuleName::Decimal, '10'],
    'decimal of precision 0' => ['a precision of 1 to 38 and a scale of 0 to the precision, got "0, 0"', RuleName::Decimal, '0', '0'],
    'decimal of precision 39' => ['got "39, 2"', RuleName::Decimal, '39', '2'],
    'decimal with the scale above the precision' => ['got "2, 3"', RuleName::Decimal, '2', '3'],
    'decimal with a negative scale' => ['got "2, -1"', RuleName::Decimal, '2', '-1'],
    'decimal with words' => ['got "ten, 2"', RuleName::Decimal, 'ten', '2'],
    'max_length without an argument' => ['The rule max_length takes one argument, got 0.', RuleName::MaxLength],
    'max_length below 0' => ['The rule max_length takes a whole number of 0 or more, got "-1".', RuleName::MaxLength, '-1'],
    'min_items with a leading zero' => ['got "01"', RuleName::MinItems, '01'],
    'min with two arguments' => ['The rule min takes one argument, got 2.', RuleName::Min, '1', '2'],
    'max without an argument' => ['The rule max takes one argument, got 0.', RuleName::Max],
    'format phone' => ['The rule format takes one of email, url, got "phone".', RuleName::Format, 'phone'],
    'in without options' => ['The rule in takes one argument, got 0.', RuleName::In],
    'items_in without options' => ['The rule items_in takes one argument, got 0.', RuleName::ItemsIn],
    'in with an option twice' => ['values that are each non-empty and given once, got "a"', RuleName::In, 'a', 'a'],
    'styles with an empty value' => ['got ""', RuleName::Styles, ''],
]);

it('reads the whole number of a rule', function (): void {
    expect(new Rule(RuleName::MaxLength, ['255'])->number())->toBe(255);
});

it('builds the rules of a field and finds a rule by name', function (): void {
    $field = rulesFor('title', [new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['20'])], presence: Presence::Required);

    expect($field->type)->toBe(RuleName::String)
        ->and($field->presence)->toBe(Presence::Required)
        ->and($field->rule(RuleName::MaxLength)?->arguments)->toBe(['20'])
        ->and($field->rule(RuleName::MinLength))->toBeNull()
        ->and($field->rule(RuleName::String)?->name)->toBe(RuleName::String);
});

it('accepts the bounds of each kind', function (RuleName $type, string $min, string $max): void {
    $field = rulesFor('value', [new Rule($type), new Rule(RuleName::Min, [$min]), new Rule(RuleName::Max, [$max])]);

    expect($field->rule(RuleName::Min)?->arguments)->toBe([$min]);
})->with([
    'integer' => [RuleName::Integer, '-5', '5'],
    'date' => [RuleName::Date, '2000-01-01', '2099-12-31'],
    'datetime' => [RuleName::Datetime, '2000-01-01T00:00:00Z', '2099-12-31T23:59:59+01:00'],
]);

it('accepts decimal bounds', function (): void {
    $field = rulesFor('price', [new Rule(RuleName::Decimal, ['6', '2']), new Rule(RuleName::Min, ['-1.50'])]);

    expect($field->type)->toBe(RuleName::Decimal);
});

it('refuses a bound that is not a value of the type rule\'s kind', function (RuleName $type, string $bound): void {
    $rules = [new Rule($type), new Rule(RuleName::Max, [$bound])];

    expect(fn (): FieldRules => rulesFor('value', $rules))
        ->toThrow(InvalidRules::class, sprintf('The bound "%s" of the field value is not a value of its type rule %s.', $bound, $type->value));
})->with([
    'integer' => [RuleName::Integer, '1.5'],
    'date' => [RuleName::Date, '2026-02-30'],
    'datetime' => [RuleName::Datetime, '2026-01-01'],
]);

it('refuses a decimal bound that is not a decimal number', function (): void {
    expect(fn (): FieldRules => rulesFor('price', [new Rule(RuleName::Decimal, ['6', '2']), new Rule(RuleName::Min, ['1e3'])]))
        ->toThrow(InvalidRules::class, 'The bound "1e3" of the field price is not a value of its type rule decimal.');
});

it('refuses rules that do not start with one type rule', function (Rule ...$rules): void {
    expect(fn (): FieldRules => rulesFor('title', array_values($rules)))
        ->toThrow(InvalidRules::class, 'The rules of the field title start with a type rule, such as string or object, and have only one.');
})->with([
    'no rules' => [],
    'a modifier first' => [new Rule(RuleName::MaxLength, ['3']), new Rule(RuleName::String)],
    'two type rules' => [new Rule(RuleName::String), new Rule(RuleName::Integer)],
]);

it('refuses a rule its type rule does not allow, and a rule given twice', function (string $type, string $rule, Rule ...$rules): void {
    expect(fn (): FieldRules => rulesFor('title', array_values($rules)))
        ->toThrow(InvalidRules::class, sprintf('The field title has the type rule %s, which the rule %s cannot follow, or it names %s twice.', $type, $rule, $rule));
})->with([
    'max_items on text' => ['string', 'max_items', new Rule(RuleName::String), new Rule(RuleName::MaxItems, ['2'])],
    'min on a boolean' => ['boolean', 'min', new Rule(RuleName::Boolean), new Rule(RuleName::Min, ['1'])],
    'max_length twice' => ['string', 'max_length', new Rule(RuleName::String), new Rule(RuleName::MaxLength, ['2']), new Rule(RuleName::MaxLength, ['3'])],
    'styles on a list' => ['list', 'styles', new Rule(RuleName::List), new Rule(RuleName::Styles, [])],
]);

it('gives nested fields only to groups, and a list either nested fields or options', function (string $type, bool $nested, Rule ...$rules): void {
    $fields = $nested ? [rulesFor('inner', [new Rule(RuleName::String)])] : [];

    expect(fn (): FieldRules => rulesFor('group', array_values($rules), $fields))
        ->toThrow(InvalidRules::class, sprintf('The field group has the type rule %s: only an object has nested fields and needs them', $type));
})->with([
    'an object without nested fields' => ['object', false, new Rule(RuleName::Object)],
    'text with nested fields' => ['string', true, new Rule(RuleName::String)],
    'a list with neither' => ['list', false, new Rule(RuleName::List)],
    'a list with both' => ['list', true, new Rule(RuleName::List), new Rule(RuleName::ItemsIn, ['a'])],
    'a repeated group that is distinct' => ['list', true, new Rule(RuleName::List), new Rule(RuleName::Distinct)],
]);

it('accepts a group, a repeated group and a list of options', function (): void {
    $inner = rulesFor('inner', [new Rule(RuleName::String)]);

    expect(rulesFor('once', [new Rule(RuleName::Object)], [$inner])->fields)->toBe([$inner])
        ->and(rulesFor('repeated', [new Rule(RuleName::List), new Rule(RuleName::MaxItems, ['3'])], [$inner])->fields)->toBe([$inner])
        ->and(rulesFor('options', [new Rule(RuleName::List), new Rule(RuleName::Distinct), new Rule(RuleName::ItemsIn, ['a'])])->fields)->toBe([]);
});

it('refuses a handle given twice among siblings, and a namespace given twice', function (): void {
    $title = rulesFor('title', [new Rule(RuleName::String)]);

    expect(fn (): FieldRules => rulesFor('group', [new Rule(RuleName::Object)], [$title, $title]))
        ->toThrow(InvalidRules::class, 'The field title is given twice among its siblings.')
        ->and(fn (): TypeRules => new TypeRules([$title, $title]))
        ->toThrow(InvalidRules::class, 'The field title is given twice among its siblings.')
        ->and(fn (): ExtensionRules => new ExtensionRules(new FieldNamespace('app'), [$title, $title]))
        ->toThrow(InvalidRules::class, 'The field title is given twice among its siblings.')
        ->and(fn (): TypeRules => new TypeRules([$title], [new ExtensionRules(new FieldNamespace('app'), [$title]), new ExtensionRules(new FieldNamespace('app'), [])]))
        ->toThrow(InvalidRules::class, 'The extension namespace app is given twice.');
});

it('lets the owner and an extender each have a field of the same handle', function (): void {
    $title = rulesFor('title', [new Rule(RuleName::String)]);
    $rules = new TypeRules([$title], [new ExtensionRules(new FieldNamespace('app'), [$title]), new ExtensionRules(new FieldNamespace('acme'), [$title])]);

    expect($rules->extensions)->toHaveCount(2)
        ->and(TypeRules::EXTENSIONS_KEY)->toBe('ext');
});

it('requires a value at the stages of each presence', function (Presence $presence, bool $write, bool $release): void {
    expect($presence->requiredAt(ValidationStage::Write))->toBe($write)
        ->and($presence->requiredAt(ValidationStage::Release))->toBe($release);
})->with([
    'required' => [Presence::Required, true, true],
    'required on release' => [Presence::RequiredOnRelease, false, true],
    'optional' => [Presence::Optional, false, false],
]);

it('reports whether the input passed, and validation_failed for the whole input', function (): void {
    $error = new CatalogError(ErrorCode::ValidationRequired, new FieldPath('title'), 'needs a value.');

    expect(new ValidationReport([])->passed())->toBeTrue()
        ->and(new ValidationReport([$error])->passed())->toBeFalse()
        ->and(new ValidationReport([$error])->code())->toBe(ErrorCode::ValidationFailed)
        ->and(new ValidationReport([$error])->errors)->toBe([$error]);
});

it('names the type rules and what may follow each', function (): void {
    $types = array_values(array_filter(RuleName::cases(), static fn (RuleName $name): bool => $name->isType()));

    expect(array_map(static fn (RuleName $name): string => $name->value, $types))
        ->toBe(['string', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'object', 'list', 'portable_text'])
        ->and(RuleName::Boolean->modifiers())->toBe([])
        ->and(RuleName::Object->modifiers())->toBe([])
        ->and(RuleName::MaxLength->modifiers())->toBe([])
        ->and(RuleName::Decimal->modifiers())->toBe([RuleName::Min, RuleName::Max])
        ->and(RuleName::Date->modifiers())->toBe([RuleName::Min, RuleName::Max])
        ->and(RuleName::Datetime->modifiers())->toBe([RuleName::Min, RuleName::Max])
        ->and(RuleName::Integer->modifiers())->toBe([RuleName::Min, RuleName::Max])
        ->and(RuleName::String->modifiers())->toBe([RuleName::MinLength, RuleName::MaxLength, RuleName::Format, RuleName::In])
        ->and(RuleName::List->modifiers())->toBe([RuleName::Distinct, RuleName::ItemsIn, RuleName::MinItems, RuleName::MaxItems])
        ->and(RuleName::PortableText->modifiers())->toBe([RuleName::Styles, RuleName::Marks, RuleName::Lists, RuleName::Links]);
});
