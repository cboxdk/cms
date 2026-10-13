<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Validation;

use Cbox\Cms\Contracts\Fields\FieldHandle;
use Cbox\Cms\Contracts\Fields\FieldNamespace;
use Cbox\Cms\Contracts\Validation\Condition;
use Cbox\Cms\Contracts\Validation\ConditionOperator;
use Cbox\Cms\Contracts\Validation\ConditionValues;
use Cbox\Cms\Contracts\Validation\ExtensionRules;
use Cbox\Cms\Contracts\Validation\FieldReference;
use Cbox\Cms\Contracts\Validation\FieldRules;
use Cbox\Cms\Contracts\Validation\InvalidRules;
use Cbox\Cms\Contracts\Validation\Presence;
use Cbox\Cms\Contracts\Validation\Rule;
use Cbox\Cms\Contracts\Validation\RuleName;
use Cbox\Cms\Contracts\Validation\TypeRules;
use Cbox\Cms\Contracts\Validation\WhenHidden;
use Override;

/*
 * The conditions of conditional fields (gap G3): the typed grammar of eq, neq, in, null, not_null,
 * all and any, read through ConditionValues, and the rules a FieldRules holds a conditional field
 * to: a condition and a when_hidden together, and references the TypeRules can resolve.
 */

/**
 * Values by reference, written `../` once per group up and the handle; a reference not listed has
 * no value.
 *
 * @param  array<string, string|int|bool|null>  $values
 */
function conditionValues(array $values): ConditionValues
{
    return new readonly class($values) implements ConditionValues
    {
        /**
         * @param  array<string, string|int|bool|null>  $values
         */
        public function __construct(private array $values) {}

        #[Override]
        public function isNull(FieldReference $field): bool
        {
            return ($this->values[$field->toString()] ?? null) === null;
        }

        #[Override]
        public function is(FieldReference $field, string|int|bool $value): bool
        {
            return ($this->values[$field->toString()] ?? null) === $value;
        }
    };
}

function textField(string $handle, ?Condition $when = null, ?WhenHidden $whenHidden = null): FieldRules
{
    return new FieldRules(new FieldHandle($handle), Presence::Optional, [new Rule(RuleName::String)], when: $when, whenHidden: $whenHidden);
}

it('holds each operator as its name says, a field without a value equal to nothing', function (Condition $condition, bool $holds): void {
    expect($condition->holds(conditionValues(['kind' => 'external', 'count' => 2, 'flag' => false, 'empty' => null, '../scale' => 'kelvin'])))->toBe($holds);
})->with([
    'eq on text' => [Condition::eq(FieldReference::sibling('kind'), 'external'), true],
    'eq of another kind' => [Condition::eq(FieldReference::sibling('count'), '2'), false],
    'eq on a boolean false' => [Condition::eq(FieldReference::sibling('flag'), false), true],
    'eq on a field without a value' => [Condition::eq(FieldReference::sibling('empty'), 'x'), false],
    'neq on text' => [Condition::neq(FieldReference::sibling('kind'), 'internal'), true],
    'neq on a field without a value' => [Condition::neq(FieldReference::sibling('missing'), true), true],
    'in' => [Condition::in(FieldReference::sibling('count'), [1, 2, 3]), true],
    'in without the value' => [Condition::in(FieldReference::sibling('count'), [1, 3]), false],
    'null on null' => [Condition::isNull(FieldReference::sibling('empty')), true],
    'null on a missing field' => [Condition::isNull(FieldReference::sibling('missing')), true],
    'null on false' => [Condition::isNull(FieldReference::sibling('flag')), false],
    'not_null on false' => [Condition::notNull(FieldReference::sibling('flag')), true],
    'a field one group up' => [Condition::eq(new FieldReference(1, new FieldHandle('scale')), 'kelvin'), true],
    'all' => [Condition::all([Condition::notNull(FieldReference::sibling('kind')), Condition::eq(FieldReference::sibling('count'), 2)]), true],
    'all with one that does not hold' => [Condition::all([Condition::notNull(FieldReference::sibling('kind')), Condition::eq(FieldReference::sibling('count'), 3)]), false],
    'any' => [Condition::any([Condition::eq(FieldReference::sibling('count'), 3), Condition::isNull(FieldReference::sibling('empty'))]), true],
    'any where none holds' => [Condition::any([Condition::eq(FieldReference::sibling('count'), 3), Condition::notNull(FieldReference::sibling('empty'))]), false],
    'any of all' => [Condition::any([Condition::all([Condition::eq(FieldReference::sibling('flag'), true)]), Condition::eq(FieldReference::sibling('kind'), 'external')]), true],
]);

it('lists the fields a condition reads in the order it names them', function (): void {
    $condition = Condition::any([Condition::eq(FieldReference::sibling('a'), 1), Condition::all([Condition::isNull(new FieldReference(2, new FieldHandle('b'))), Condition::notNull(FieldReference::sibling('c'))])]);

    expect(array_map(static fn (FieldReference $reference): string => $reference->toString(), $condition->references()))->toBe(['a', '../../b', 'c']);
});

it('refuses arguments that do not fit the operator', function (callable $make): void {
    expect($make)->toThrow(InvalidRules::class);
})->with([
    'eq without a value' => [static fn (): Condition => new Condition(ConditionOperator::Eq, FieldReference::sibling('a'))],
    'neq with two values' => [static fn (): Condition => new Condition(ConditionOperator::Neq, FieldReference::sibling('a'), ['x', 'y'])],
    'in without values' => [static fn (): Condition => Condition::in(FieldReference::sibling('a'), [])],
    'null with a value' => [static fn (): Condition => new Condition(ConditionOperator::Null, FieldReference::sibling('a'), [true])],
    'all without conditions' => [static fn (): Condition => Condition::all([])],
    'any with a field' => [static fn (): Condition => new Condition(ConditionOperator::Any, FieldReference::sibling('a'), conditions: [Condition::isNull(FieldReference::sibling('a'))])],
    'a reference too many groups up' => [static fn (): FieldReference => new FieldReference(FieldReference::MAX_UP + 1, new FieldHandle('a'))],
]);

it('requires a condition and a when_hidden together', function (): void {
    expect(fn (): FieldRules => textField('a', Condition::isNull(FieldReference::sibling('b'))))->toThrow(InvalidRules::class, 'has a condition and no when_hidden')
        ->and(fn (): FieldRules => textField('a', null, WhenHidden::Keep))->toThrow(InvalidRules::class)
        ->and(textField('a', Condition::isNull(FieldReference::sibling('b')), WhenHidden::Clear)->shownFor(conditionValues([])))->toBeTrue()
        ->and(textField('a')->shownFor(conditionValues(['b' => 'x'])))->toBeTrue();
});

it('refuses a condition that names a field the type\'s rules do not have where the reference goes', function (): void {
    $group = static fn (FieldRules ...$fields): FieldRules => new FieldRules(new FieldHandle('group'), Presence::Optional, [new Rule(RuleName::Object)], array_values($fields));
    $reads = static fn (int $up, string $handle): FieldRules => textField('reader', Condition::notNull(new FieldReference($up, new FieldHandle($handle))), WhenHidden::Keep);

    expect(new TypeRules([textField('kind'), $reads(0, 'kind')])->fields)->toHaveCount(2)
        ->and(new TypeRules([textField('kind'), $group($reads(1, 'kind'))])->fields)->toHaveCount(2)
        ->and(fn (): TypeRules => new TypeRules([textField('kind'), $reads(0, 'other')]))->toThrow(InvalidRules::class, 'names other')
        ->and(fn (): TypeRules => new TypeRules([textField('kind'), $reads(1, 'kind')]))->toThrow(InvalidRules::class)
        ->and(fn (): TypeRules => new TypeRules([$group(textField('kind')), $group($reads(0, 'kind'))]))->toThrow(InvalidRules::class)
        ->and(fn (): ExtensionRules => new ExtensionRules(new FieldNamespace('acme'), [$reads(0, 'kind')]))->toThrow(InvalidRules::class);
});
