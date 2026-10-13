<?php

declare(strict_types=1);

use Cbox\Cms\Contracts\Results\CatalogError;
use Cbox\Cms\Contracts\Validation\Condition;
use Cbox\Cms\Contracts\Validation\ConditionValues;
use Cbox\Cms\Contracts\Validation\FieldReference;
use Cbox\Cms\Contracts\Validation\ValidationReport;
use Cbox\Cms\Contracts\Validation\ValidationStage;
use Cbox\Cms\Core\Validation\Boundary\InputValidator;
use Examples\Unit\Validation\LinkValidator;

// A conditional field is required only while it is shown, at every stage, and a hidden field of
// when_hidden: clear may hold no value. A hidden field of keep keeps its value, which its rules
// still check.

/**
 * @return list<string>
 */
function linkErrors(ValidationReport $report): array
{
    return array_map(static fn (CatalogError $error): string => $error->path?->toString().': '.$error->code->value, $report->errors);
}

it('requires the URL only while the target is external, and refuses one for a page', function (): void {
    $validator = new InputValidator;

    expect(linkErrors($validator->validateWith(new LinkValidator, ['target' => 'page'], ValidationStage::Release)))->toBe([])
        ->and(linkErrors($validator->validateWith(new LinkValidator, ['target' => 'external'])))->toBe(['url: validation_required'])
        ->and(linkErrors($validator->validateWith(new LinkValidator, ['target' => 'external', 'url' => 'https://example.org'])))->toBe([])
        ->and(linkErrors($validator->validateWith(new LinkValidator, ['target' => 'page', 'url' => 'https://example.org'])))->toBe(['url: validation_hidden_field']);
});

it('reads a sibling and, one group up, the link\'s target for a nested field that keeps its value', function (): void {
    $validator = new InputValidator;
    $tracking = static fn (string $target, array $fields): array => ['target' => $target, 'url' => $target === 'external' ? 'https://example.org' : null, 'tracking' => $fields];

    expect(linkErrors($validator->validateWith(new LinkValidator, $tracking('external', ['campaign' => 'spring', 'medium' => 'mail']))))->toBe([])
        ->and(linkErrors($validator->validateWith(new LinkValidator, $tracking('page', ['campaign' => 'spring', 'medium' => 'mail']))))->toBe([])
        ->and(linkErrors($validator->validateWith(new LinkValidator, $tracking('page', ['medium' => str_repeat('m', 21)]))))->toBe(['tracking.medium: validation_too_long']);
});

it('evaluates a condition over values of another form through ConditionValues', function (): void {
    // A form's state, by handle, read as the condition reads values: missing or null is no value.
    $state = ['target' => 'external', 'url' => null];
    $values = new readonly class($state) implements ConditionValues
    {
        /**
         * @param  array<string, string|int|bool|null>  $state
         */
        public function __construct(private array $state) {}

        public function isNull(FieldReference $field): bool
        {
            return ($this->state[$field->handle->value] ?? null) === null;
        }

        public function is(FieldReference $field, string|int|bool $value): bool
        {
            return ($this->state[$field->handle->value] ?? null) === $value;
        }
    };

    expect(Condition::eq(FieldReference::sibling('target'), 'external')->holds($values))->toBeTrue()
        ->and(Condition::all([Condition::notNull(FieldReference::sibling('target')), Condition::notNull(FieldReference::sibling('url'))])->holds($values))->toBeFalse();
});
